<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use App\Tests\Support\FakeClamd;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Phase 2 acceptance: the whole life of a document through the HTTP API, as different
 * people, against the real database, with a ClamAV stand-in speaking the real protocol.
 */
final class DocumentLifecycleTest extends WebTestCase
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
        $this->tmp = sys_get_temp_dir().'/pal-doc-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);

        $this->users['author'] = self::createUser($this->db, 'a0000000-0000-4000-8000-000000000001', 'Ada Author');
        $this->users['approver'] = self::createUser($this->db, 'a0000000-0000-4000-8000-000000000002', 'Abe Approver');
        $this->users['publisher'] = self::createUser($this->db, 'a0000000-0000-4000-8000-000000000003', 'Pat Publisher');
        $this->users['reader'] = self::createUser($this->db, 'a0000000-0000-4000-8000-000000000004', 'Rita Reader');
        self::grantRole($this->db, $this->users['author'], 'AUTHOR');
        self::grantRole($this->db, $this->users['publisher'], 'PUBLISHER');
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

    private function upload(string $csrf, string $filename, string $content): string
    {
        $path = $this->tmp.'/'.bin2hex(random_bytes(4)).'-'.$filename;
        file_put_contents($path, $content);
        $this->client->request('POST', '/api/uploads', files: ['file' => new UploadedFile($path, $filename, null, null, true)], server: ['HTTP_X_CSRF_TOKEN' => $csrf]);

        return (string) ($this->json()['id'] ?? '');
    }

    /**
     * Creates a document as the author with an approver grant, and returns [documentId, revisionId].
     *
     * @return array{0: string, 1: string}
     */
    private function draftDocument(string $content = "%PDF-1.4\n% revision one\n%%EOF\n", bool $restricted = false): array
    {
        $csrf = $this->as('author');
        $uploadId = $this->upload($csrf, 'Calibration.pdf', $content);
        self::assertResponseStatusCodeSame(201);
        $created = $this->call($csrf, 'POST', '/api/documents', [
            'typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Calibration of torque tools',
            'description' => 'How QA calibrates torque wrenches.', 'keywords' => ['calibration', 'metrology'],
            'uploadId' => $uploadId, 'restricted' => $restricted,
        ]);
        self::assertResponseStatusCodeSame(201);
        $this->call($csrf, 'POST', '/api/documents/'.$created['id'].'/grants', ['role' => 'APPROVER', 'userId' => $this->users['approver']]);
        self::assertResponseStatusCodeSame(201);

        return [(string) $created['id'], (string) $created['revisionId']];
    }

    private function submitAndApprove(string $revisionId): void
    {
        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        $csrf = $this->as('approver');
        $this->client->request('GET', '/api/workspace');
        $taskId = (string) $this->json()['tasks'][0]['taskId'];
        $this->call($csrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'APPROVE']);
        self::assertResponseIsSuccessful();
    }

    public function testScenario1CreateApprovePublishFindAndDownload(): void
    {
        $content = "%PDF-1.4\n% the approved bytes\n%%EOF\n";
        [$documentId, $revisionId] = $this->draftDocument($content);
        self::assertSame('SOP-QA-0001', $this->db->fetchOne('SELECT document_number FROM document WHERE id = ?', [$documentId]));

        $this->submitAndApprove($revisionId);
        $csrf = $this->as('publisher');
        $released = $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseIsSuccessful();
        self::assertFalse($released['alreadyReleased']);

        // Any employee finds it and gets exactly the approved bytes.
        $this->as('reader');
        $this->client->request('GET', '/api/library?q=torque');
        $library = $this->json();
        self::assertSame(1, $library['total']);
        self::assertSame('SOP-QA-0001', $library['items'][0]['documentNumber']);
        self::assertSame('1.0', $library['items'][0]['revision']);
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertSame('1.0', $this->json()['current']['label']);
        $this->client->request('GET', '/api/revisions/'.$revisionId.'/file');
        self::assertResponseIsSuccessful();
        self::assertSame($content, $this->client->getInternalResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        // The whole story is in the audit trail, in order.
        self::assertSame(
            ['UPLOAD_ACCEPTED', 'DOCUMENT_CREATED', 'REVISION_CREATED', 'DOCUMENT_ACCESS_GRANTED', 'REVISION_SUBMITTED', 'REVISION_APPROVED', 'REVISION_RELEASED'],
            $this->db->fetchFirstColumn("SELECT event_type FROM audit_event WHERE event_type NOT IN ('USER_PROVISIONED') ORDER BY id"),
        );
    }

    public function testScenario7ReadersCannotReachDraftsByAnyRoute(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();

        $this->as('reader');
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/revisions/'.$revisionId.'/file');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/library?q=torque');
        $byTitle = $this->json();
        $this->client->request('GET', '/api/library?q=SOP-QA-0001');
        $byNumber = $this->json();
        self::assertSame(0, $byTitle['total']);
        self::assertSame(0, $byNumber['total']);
    }

    public function testPublishedDocumentHidesTheNextDraftFromReaders(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();
        $this->submitAndApprove($revisionId);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');

        $csrf = $this->as('author');
        $next = $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/revisions');
        self::assertResponseStatusCodeSame(201);
        $draftId = (string) $next['revisionId'];

        $this->as('reader');
        $this->client->request('GET', '/api/documents/'.$documentId);
        $view = $this->json();
        self::assertSame('1.0', $view['current']['label']);
        self::assertNull($view['openRevision'], 'readers never see the working draft');
        $this->client->request('GET', '/api/revisions/'.$draftId.'/file');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReleasingTheNextRevisionSupersedesWithoutLosingHistory(): void
    {
        [$documentId, $first] = $this->draftDocument();
        $this->submitAndApprove($first);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$first.'/release');

        $csrf = $this->as('author');
        $second = (string) $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/revisions')['revisionId'];
        $this->client->request('GET', '/api/documents/'.$documentId);
        $rowVersion = (int) $this->json()['openRevision']['rowVersion'];
        $this->call($csrf, 'PATCH', '/api/revisions/'.$second, ['title' => 'Calibration of torque tools', 'changeSummary' => 'Added digital wrenches', 'keywords' => ['calibration'], 'rowVersion' => $rowVersion]);
        self::assertResponseIsSuccessful();
        $this->submitAndApprove($second);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$second.'/release');

        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM publication WHERE document_id = ? AND status = 'RELEASED' AND effective_period @> now()", [$documentId]));
        $this->as('author');
        $this->client->request('GET', '/api/documents/'.$documentId);
        $view = $this->json();
        self::assertSame('2.0', $view['current']['label']);
        self::assertSame(['2.0', '1.0'], array_column($view['history'], 'label'));
        self::assertFalse($view['history'][1]['isCurrent']);
        self::assertNotNull($view['history'][1]['effectiveTo']);
        self::assertContains('REVISION_SUPERSEDED', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));

        // Readers get only the current version's file; superseded copies are not handed out.
        $this->as('reader');
        $this->client->request('GET', '/api/revisions/'.$first.'/file');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReleaseIsIdempotent(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();
        $this->submitAndApprove($revisionId);
        $csrf = $this->as('publisher');

        $first = $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/release');
        $second = $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/release');

        self::assertTrue($second['alreadyReleased']);
        self::assertSame($first['publicationId'], $second['publicationId']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM publication WHERE document_id = ?', [$documentId]));
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'REVISION_RELEASED'"));
    }

    public function testSeparationOfDutiesAndApprovalRules(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();
        $csrf = $this->as('author');

        // The author cannot pick themselves, and the owner cannot be granted approver.
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['author']]);
        self::assertResponseStatusCodeSame(422);
        $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'APPROVER', 'userId' => $this->users['author']]);
        self::assertResponseStatusCodeSame(422);
        // Only people holding the approver grant can be chosen.
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['reader']]);
        self::assertResponseStatusCodeSame(422);

        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        // Nobody but the assignee can decide; content can no longer be edited.
        $taskId = (string) $this->db->fetchOne('SELECT t.id FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id WHERE i.revision_id = ?', [$revisionId]);
        $this->call($csrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'APPROVE']);
        self::assertResponseStatusCodeSame(404);
        $this->call($csrf, 'PATCH', '/api/revisions/'.$revisionId, ['title' => 'Changed after submit', 'rowVersion' => 2]);
        self::assertResponseStatusCodeSame(409);

        // Rejection needs a reason, and the revision goes back to the author.
        $approverCsrf = $this->as('approver');
        $this->call($approverCsrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'REJECT']);
        self::assertResponseStatusCodeSame(422);
        $this->call($approverCsrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'REQUEST_CHANGES', 'comment' => 'Add the calibration interval.']);
        self::assertResponseIsSuccessful();
        // A second decision on the same task is refused.
        $this->call($approverCsrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'APPROVE']);
        self::assertResponseStatusCodeSame(409);

        // Readers and non-publishers cannot release.
        $this->call($this->as('approver'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('CHANGES_REQUESTED', $this->db->fetchOne('SELECT state FROM revision WHERE id = ?', [$revisionId]));
    }

    public function testAnyoneWhoEditedARevisionCannotApproveIt(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();
        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'AUTHOR', 'userId' => $this->users['approver']]);
        self::assertResponseStatusCodeSame(201);
        $rowVersion = (int) $this->db->fetchOne('SELECT row_version FROM revision WHERE id = ?', [$revisionId]);

        // The approver edits the content through their author grant, then is chosen to approve it.
        $this->call($this->as('approver'), 'PATCH', '/api/revisions/'.$revisionId, ['title' => 'Rewritten by the approver', 'rowVersion' => $rowVersion]);
        self::assertResponseIsSuccessful();
        $csrf = $this->as('author');
        $this->client->request('GET', '/api/documents/'.$documentId.'/approvers');
        self::assertSame([], $this->json()['approvers']);
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRevokedApproverCannotDecideAnOpenTask(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();
        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        $grantId = (string) $this->db->fetchOne("SELECT id FROM document_grant WHERE document_id = ? AND role = 'APPROVER'", [$documentId]);
        $this->call($csrf, 'DELETE', '/api/documents/'.$documentId.'/grants/'.$grantId);
        self::assertResponseIsSuccessful();

        $taskId = (string) $this->db->fetchOne('SELECT t.id FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id WHERE i.revision_id = ?', [$revisionId]);
        $this->call($this->as('approver'), 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'APPROVE']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('IN_WORKFLOW', $this->db->fetchOne('SELECT state FROM revision WHERE id = ?', [$revisionId]));
    }

    public function testGroupGrantsAreAuthorizedBeforeAnyDirectoryLookup(): void
    {
        [$documentId] = $this->draftDocument();
        $groupObjectId = 'b0000000-0000-4000-8000-0000000000ff';

        $this->call($this->as('reader'), 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'READER', 'groupObjectId' => $groupObjectId]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM directory_group WHERE idp_object_id = ?', [$groupObjectId]));
    }

    public function testDailyUploadQuotaIsEnforced(): void
    {
        $csrf = $this->as('author');
        $this->db->executeStatement(
            "INSERT INTO upload (uploaded_by, original_filename, size_bytes, status, reason) VALUES (?, 'big.pdf', 524288000, 'REJECTED', 'x')",
            [$this->users['author']],
        );
        // Refused uploads do not count against the quota.
        $this->upload($csrf, 'a.pdf', "%PDF-1.4\n% a\n%%EOF\n");
        self::assertResponseStatusCodeSame(201);

        $this->db->executeStatement("UPDATE upload SET size_bytes = 524288000 WHERE uploaded_by = ? AND status = 'CLEAN'", [$this->users['author']]);
        $this->upload($csrf, 'b.pdf', "%PDF-1.4\n% b\n%%EOF\n");
        self::assertResponseStatusCodeSame(429);
    }

    public function testScenario3RejectionReworkAndResubmission(): void
    {
        [, $revisionId] = $this->draftDocument();
        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        $taskId = (string) $this->db->fetchOne('SELECT t.id FROM workflow_task t JOIN workflow_instance i ON i.id = t.instance_id WHERE i.revision_id = ?', [$revisionId]);
        $this->call($this->as('approver'), 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'REJECT', 'comment' => 'Wrong torque values.']);

        $csrf = $this->as('author');
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/reopen');
        self::assertResponseIsSuccessful();
        $rowVersion = (int) $this->db->fetchOne('SELECT row_version FROM revision WHERE id = ?', [$revisionId]);
        $this->call($csrf, 'PATCH', '/api/revisions/'.$revisionId, ['title' => 'Calibration of torque tools (corrected)', 'rowVersion' => $rowVersion]);
        self::assertResponseIsSuccessful();
        $this->call($csrf, 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();

        // The new workflow is bound to the new content; the old decision does not carry over.
        $hashes = $this->db->fetchFirstColumn('SELECT bound_manifest_hash FROM workflow_instance WHERE revision_id = ? ORDER BY started_at', [$revisionId]);
        self::assertCount(2, $hashes);
        self::assertNotSame($hashes[0], $hashes[1]);
        self::assertSame('IN_WORKFLOW', $this->db->fetchOne('SELECT state FROM revision WHERE id = ?', [$revisionId]));
    }

    public function testScenario8TamperedApprovedContentIsNotReleased(): void
    {
        [, $revisionId] = $this->draftDocument();
        $this->submitAndApprove($revisionId);
        // Simulate tampering below the application: bypass the trigger and swap the file.
        $this->db->executeStatement("INSERT INTO stored_file (sha256, size_bytes, detected_mime, scan_engine, scanned_at, storage_backend) VALUES (repeat('e', 64), 5, 'application/pdf', 'test', now(), 'filesystem')");
        $this->db->executeStatement('ALTER TABLE revision_file DISABLE TRIGGER trg_revision_file_draft_only');
        $this->db->executeStatement("UPDATE revision_file SET sha256 = repeat('e', 64) WHERE revision_id = ?", [$revisionId]);
        $this->db->executeStatement('ALTER TABLE revision_file ENABLE TRIGGER trg_revision_file_draft_only');

        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('approval_integrity_failed', $this->json()['error']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM publication'));
    }

    public function testRestrictedDocumentsAreVisibleOnlyToGrantees(): void
    {
        [$documentId, $revisionId] = $this->draftDocument(restricted: true);
        $this->submitAndApprove($revisionId);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');

        $this->as('reader');
        $this->client->request('GET', '/api/library');
        self::assertSame(0, $this->json()['total']);
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertResponseStatusCodeSame(404);

        $this->call($this->as('author'), 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'READER', 'userId' => $this->users['reader']]);
        $this->as('reader');
        $this->client->request('GET', '/api/library');
        self::assertSame(1, $this->json()['total']);
    }

    public function testMalwareAndDisguisedFilesNeverBecomeStoredFiles(): void
    {
        $csrf = $this->as('author');

        $this->upload($csrf, 'notes.txt', 'Meeting notes '.FakeClamd::eicar());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('malware_detected', $this->json()['error']);

        $this->upload($csrf, 'manual.docx', FakeClamd::minimalPdf());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('file_type_not_allowed', $this->json()['error']);

        $this->upload($csrf, 'invoice.pdf', "MZ\x90\x00".str_repeat("\x00", 60).'This program cannot be run in DOS mode');
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM stored_file'));
        self::assertSame(['INFECTED', 'REJECTED', 'REJECTED'], $this->db->fetchFirstColumn('SELECT status FROM upload ORDER BY created_at'));
        self::assertContains('UPLOAD_MALWARE_DETECTED', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
    }

    public function testUploadsFailClosedWhenTheScannerIsUnavailable(): void
    {
        self::setEnv('PAL_CLAMD_ADDRESS', 'tcp://127.0.0.1:1');
        $csrf = $this->as('author');

        $this->upload($csrf, 'ok.pdf', FakeClamd::minimalPdf());

        self::assertResponseStatusCodeSame(503);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM stored_file'));
    }

    public function testUploadsCanOnlyBeAttachedByTheirUploader(): void
    {
        $csrf = $this->as('author');
        $uploadId = $this->upload($csrf, 'mine.pdf', FakeClamd::minimalPdf());

        self::grantRole($this->db, $this->users['reader'], 'AUTHOR');
        $otherCsrf = $this->as('reader');
        $this->call($otherCsrf, 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Steal', 'uploadId' => $uploadId]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDocumentCreationRequiresTheAuthorRole(): void
    {
        $csrf = $this->as('reader');
        $this->call($csrf, 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Nope']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDocumentNumbersAreSequentialPerTypeAndDepartment(): void
    {
        $csrf = $this->as('author');
        $numbers = [];
        for ($i = 0; $i < 3; ++$i) {
            $numbers[] = $this->call($csrf, 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Doc '.$i])['documentNumber'];
        }

        self::assertSame(['SOP-QA-0001', 'SOP-QA-0002', 'SOP-QA-0003'], $numbers);
    }

    public function testEligibleApproversAreListedForEditorsOnly(): void
    {
        [$documentId] = $this->draftDocument();

        $this->as('author');
        $this->client->request('GET', '/api/documents/'.$documentId.'/approvers');
        self::assertSame(['Abe Approver'], array_column($this->json()['approvers'], 'displayName'));

        $this->as('reader');
        $this->client->request('GET', '/api/documents/'.$documentId.'/approvers');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublishersCanReviewWhatTheyReleaseButNotDrafts(): void
    {
        [$documentId, $revisionId] = $this->draftDocument();

        // A draft is invisible to publishers.
        $this->as('publisher');
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertResponseStatusCodeSame(404);

        $this->submitAndApprove($revisionId);

        // Once approved, the publisher sees it, can download the approved file, and is offered release.
        $this->as('publisher');
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        self::assertContains('release', $this->json()['awaitingRelease']['actions']);
        $this->client->request('GET', '/api/revisions/'.$revisionId.'/file');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/workspace');
        self::assertSame($documentId, $this->json()['awaitingRelease'][0]['documentId']);
    }
}
