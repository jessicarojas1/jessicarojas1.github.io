<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Security\PalUserProvider;
use App\Storage\UnattachedUploadScanner;
use App\Tests\Support\DatabaseReset;
use App\Tests\Support\FakeClamd;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images in editor content (decision #29): sanitized on upload, usable only by whoever may,
 * served only to whoever may see them, covered by approval and kept out of storage clean-up.
 */
final class ContentImageTest extends WebTestCase
{
    use DatabaseReset;

    private static ?FakeClamd $clamd = null;
    private KernelBrowser $client;
    private Connection $db;
    private string $tmp;
    /** @var array<string, string> */
    private array $users = [];
    private string $typeId;
    private string $deptId;

    public static function setUpBeforeClass(): void
    {
        self::$clamd = new FakeClamd();
    }

    public static function tearDownAfterClass(): void
    {
        self::$clamd?->stop();
        self::$clamd = null;
    }

    protected function setUp(): void
    {
        self::setEnv('PAL_CLAMD_ADDRESS', (string) self::$clamd?->address);
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
        $this->tmp = sys_get_temp_dir().'/pal-img-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
        foreach (['author' => 'Ada Author', 'other' => 'Oscar Otherauthor', 'approver' => 'Abe Approver', 'publisher' => 'Pat Publisher', 'reader' => 'Rita Reader', 'admin' => 'Pam Administrator'] as $key => $name) {
            $this->users[$key] = self::createUser($this->db, 'c0000000-0000-4000-8000-'.str_pad((string) (\count($this->users) + 1), 12, '0', \STR_PAD_LEFT), $name);
        }
        self::grantRole($this->db, $this->users['author'], 'AUTHOR');
        self::grantRole($this->db, $this->users['other'], 'AUTHOR');
        self::grantRole($this->db, $this->users['publisher'], 'PUBLISHER');
        self::grantRole($this->db, $this->users['admin'], 'PAL_ADMINISTRATOR');
        $this->typeId = (string) $this->db->fetchOne("SELECT id FROM document_type WHERE code = 'SOP'");
        $this->deptId = (string) $this->db->fetchOne("INSERT INTO org_unit (code, name) VALUES ('QA', 'Quality Assurance') RETURNING id");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        self::setEnv('PAL_CLAMD_ADDRESS', '');
        parent::tearDown();
    }

    private static function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }

    private function as(string $who): string
    {
        $this->client->loginUser(self::getContainer()->get(PalUserProvider::class)->loadUserByIdentifier($this->users[$who]));
        $this->client->request('GET', '/api/me');

        return (string) $this->json()['csrfToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $csrf, string $method, string $uri, array $body = []): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body));

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $csrf, string $filename, string $bytes, bool $image = true): array
    {
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'-'.$filename;
        file_put_contents($path, $bytes);
        $this->client->request('POST', '/api/uploads', $image ? ['purpose' => 'image'] : [], ['file' => new UploadedFile($path, $filename, null, null, true)], ['HTTP_X_CSRF_TOKEN' => $csrf]);

        return $this->json();
    }

    private static function png(int $width = 8, int $height = 4): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG carrying EXIF with orientation 6 (rotate 90° clockwise) and a marker string
     * standing in for GPS data, inserted as an APP1 segment right after the SOI marker.
     */
    private static function jpegWithExif(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        // TIFF (little-endian): header, IFD0 with one entry: Orientation (0x0112) SHORT 1 = 6.
        $tiff = "II*\x00".pack('V', 8).pack('v', 1).pack('vvVV', 0x0112, 3, 1, 6).pack('V', 0).'SECRET-GPS-52.0N-4.3E';
        $payload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', \strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyWithImage(string $sha, string $alt = 'Torque wrench'): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'See the picture.']]],
            ['type' => 'image', 'attrs' => ['sha256' => $sha, 'alt' => $alt]],
        ]];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function draft(string $who = 'author'): array
    {
        $csrf = $this->as($who);
        $created = $this->call($csrf, 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Wrench care']);
        self::assertResponseStatusCodeSame(201);

        return [(string) $created['id'], (string) $created['revisionId']];
    }

    private function rowVersion(string $revisionId): int
    {
        return (int) $this->db->fetchOne('SELECT row_version FROM revision WHERE id = ?', [$revisionId]);
    }

    public function testImagesAreStrippedOfMetadataAndUprighted(): void
    {
        $uploaded = $this->upload($this->as('author'), 'photo.jpg', self::jpegWithExif(20, 10));
        self::assertResponseStatusCodeSame(201);
        $sha = (string) $uploaded['sha256'];

        $original = $this->db->fetchAssociative('SELECT sha256, stored_sha256 FROM upload WHERE id = ?', [$uploaded['id']]);
        self::assertIsArray($original);
        self::assertNotSame($original['sha256'], $original['stored_sha256'], 'The stored bytes are a re-encoding, not the upload.');

        $this->client->request('GET', '/api/images/'.$sha);
        self::assertResponseIsSuccessful();
        $stored = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString('SECRET-GPS', $stored);
        self::assertStringNotContainsString('Exif', $stored);
        $size = getimagesizefromstring($stored);
        self::assertIsArray($size);
        self::assertSame([10, 20], [$size[0], $size[1]], 'Orientation 6 is applied to the pixels.');
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testOnlyRealImagesWithinLimitsAreAccepted(): void
    {
        $csrf = $this->as('author');
        $this->upload($csrf, 'doc.pdf', "%PDF-1.4\n%%EOF\n");
        self::assertResponseStatusCodeSame(422);
        $this->upload($csrf, 'diagram.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertResponseStatusCodeSame(422);
        $this->upload($csrf, 'photo.jpg', self::png());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('does not match', (string) $this->json()['message']);
        $this->upload($csrf, 'wide.png', self::png(12_001, 1));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_too_large', $this->json()['error']);
        // An image is not a document file: the normal upload still refuses it.
        $this->upload($csrf, 'pic.png', self::png(), false);
        self::assertResponseStatusCodeSame(422);
    }

    public function testImagesInADocumentAreUsableAndVisibleOnlyToThoseWhoMay(): void
    {
        [$documentId, $revisionId] = $this->draft();
        $csrf = $this->as('author');
        $sha = (string) $this->upload($csrf, 'wrench.png', self::png())['sha256'];
        $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::bodyWithImage($sha), 'rowVersion' => $this->rowVersion($revisionId)]);
        self::assertResponseIsSuccessful();
        self::assertSame($sha, $this->db->fetchOne('SELECT sha256 FROM revision_image WHERE revision_id = ?', [$revisionId]));

        // Another author cannot embed it in their own document just by knowing its hash.
        [, $otherRevision] = $this->draft('other');
        $this->call($this->as('other'), 'PUT', '/api/revisions/'.$otherRevision.'/body', ['body' => self::bodyWithImage($sha), 'rowVersion' => $this->rowVersion($otherRevision)]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('body', $this->json()['fields']);

        // A reader cannot see an image of a draft, in context or not.
        $this->as('reader');
        $this->client->request('GET', '/api/images/'.$sha.'?revision='.$revisionId);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/images/'.$sha);
        self::assertResponseStatusCodeSame(404);

        // Approve and release: now readers see it in the published revision.
        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'APPROVER', 'userId' => $this->users['approver']]);
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        $csrf = $this->as('approver');
        $this->client->request('GET', '/api/images/'.$sha.'?revision='.$revisionId);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/workspace');
        $this->call($csrf, 'POST', '/api/tasks/'.$this->json()['tasks'][0]['taskId'].'/decision', ['decision' => 'APPROVE']);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseIsSuccessful();
        $this->as('reader');
        $this->client->request('GET', '/api/images/'.$sha.'?revision='.$revisionId);
        self::assertResponseIsSuccessful();
        // ...but not through some other revision that does not show it.
        $this->client->request('GET', '/api/images/'.$sha.'?revision='.$otherRevision);
        self::assertResponseStatusCodeSame(404);

        // Storage clean-up never sees an image in use as "unattached", and the database
        // refuses to drop its stored_file row.
        $report = self::getContainer()->get(UnattachedUploadScanner::class)->scan(0, 50);
        self::assertSame(0, $report->fileCount);
        try {
            $this->db->executeStatement('DELETE FROM stored_file WHERE sha256 = ?', [$sha]);
            self::fail('An image in use must not be deletable.');
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
        }

        // The next revision keeps the image.
        $next = $this->call($this->as('author'), 'POST', '/api/documents/'.$documentId.'/revisions');
        $nextId = (string) ($next['revisionId'] ?? $next['id'] ?? '');
        self::assertSame($sha, $this->db->fetchOne('SELECT sha256 FROM revision_image WHERE revision_id = ?', [$nextId]));
    }

    public function testReleaseRefusesWhenAnApprovedImageWasTamperedWith(): void
    {
        [$documentId, $revisionId] = $this->draft();
        $csrf = $this->as('author');
        $sha = (string) $this->upload($csrf, 'wrench.png', self::png())['sha256'];
        $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::bodyWithImage($sha), 'rowVersion' => $this->rowVersion($revisionId)]);
        $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'APPROVER', 'userId' => $this->users['approver']]);
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        $csrf = $this->as('approver');
        $this->client->request('GET', '/api/workspace');
        $this->call($csrf, 'POST', '/api/tasks/'.$this->json()['tasks'][0]['taskId'].'/decision', ['decision' => 'APPROVE']);
        self::assertResponseIsSuccessful();

        $path = self::blobPath($sha);
        chmod($path, 0o640);
        file_put_contents($path, self::png(3, 3));
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('approval_integrity_failed', $this->json()['error']);
    }

    public function testTemplatesCarryTheirImagesIntoNewDocuments(): void
    {
        $csrf = $this->as('admin');
        $sha = (string) $this->upload($csrf, 'logo.png', self::png())['sha256'];
        $created = $this->call($csrf, 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'With figure', 'body' => self::bodyWithImage($sha, 'Process diagram')]);
        self::assertResponseStatusCodeSame(201);
        $templateId = (string) $created['id'];
        $this->client->request('GET', '/api/images/'.$sha.'?template='.$templateId);
        self::assertResponseIsSuccessful();

        $csrf = $this->as('author');
        $doc = $this->call($csrf, 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'From template', 'templateId' => $templateId]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($sha, $this->db->fetchOne('SELECT sha256 FROM revision_image WHERE revision_id = ?', [$doc['revisionId']]));
        $this->client->request('GET', '/api/images/'.$sha.'?revision='.$doc['revisionId']);
        self::assertResponseIsSuccessful();
        // The author may keep the template's image while editing their copy.
        $this->call($csrf, 'PUT', '/api/revisions/'.$doc['revisionId'].'/body', ['body' => self::bodyWithImage($sha, 'Updated description'), 'rowVersion' => $this->rowVersion((string) $doc['revisionId'])]);
        self::assertResponseIsSuccessful();
        // Authors cannot read template images through the admin context.
        $this->client->request('GET', '/api/images/'.$sha.'?template='.$templateId);
        self::assertResponseStatusCodeSame(404);
    }

    private static function blobPath(string $sha): string
    {
        $root = (string) ($_ENV['PAL_BLOB_DIR'] ?? $_SERVER['PAL_BLOB_DIR']);
        if (!str_starts_with($root, '/')) {
            $root = \dirname(__DIR__, 2).'/'.$root;
        }

        return rtrim($root, '/').'/sha256/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    }
}
