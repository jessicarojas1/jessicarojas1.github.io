<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Native editor v1 (ADR-0009) through the HTTP API: documents written in PAL instead of
 * uploaded, approvals bound to the exact body, and administrator-controlled templates.
 */
final class NativeEditorTest extends WebTestCase
{
    use DatabaseReset;

    private KernelBrowser $client;
    private Connection $db;
    /** @var array<string, string> */
    private array $users = [];
    private string $typeId;
    private string $otherTypeId;
    private string $deptId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);

        $this->users['author'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000001', 'Ada Author');
        $this->users['approver'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000002', 'Abe Approver');
        $this->users['publisher'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000003', 'Pat Publisher');
        $this->users['reader'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000004', 'Rita Reader');
        $this->users['admin'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000005', 'Pam Administrator');
        self::grantRole($this->db, $this->users['author'], 'AUTHOR');
        self::grantRole($this->db, $this->users['publisher'], 'PUBLISHER');
        self::grantRole($this->db, $this->users['admin'], 'PAL_ADMINISTRATOR');
        $this->typeId = (string) $this->db->fetchOne("SELECT id FROM document_type WHERE code = 'SOP'");
        $this->otherTypeId = (string) $this->db->fetchOne("SELECT id FROM document_type WHERE code = 'POL'");
        $this->deptId = (string) $this->db->fetchOne("INSERT INTO org_unit (code, name) VALUES ('QA', 'Quality Assurance') RETURNING id");
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
    private static function body(string $heading, string $text): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $heading]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{0: string, 1: string}
     */
    private function createDocument(array $extra = []): array
    {
        $csrf = $this->as('author');
        $created = $this->call($csrf, 'POST', '/api/documents', [
            'typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'Calibration of torque tools', ...$extra,
        ]);
        self::assertResponseStatusCodeSame(201);
        $this->call($csrf, 'POST', '/api/documents/'.$created['id'].'/grants', ['role' => 'APPROVER', 'userId' => $this->users['approver']]);
        self::assertResponseStatusCodeSame(201);

        return [(string) $created['id'], (string) $created['revisionId']];
    }

    private function rowVersion(string $revisionId): int
    {
        return (int) $this->db->fetchOne('SELECT row_version FROM revision WHERE id = ?', [$revisionId]);
    }

    private function writeBody(string $revisionId, mixed $body): void
    {
        $this->call($this->as('author'), 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => $body, 'rowVersion' => $this->rowVersion($revisionId)]);
    }

    private function approve(string $revisionId): void
    {
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        $csrf = $this->as('approver');
        $this->client->request('GET', '/api/workspace');
        $taskId = (string) $this->json()['tasks'][0]['taskId'];
        $this->call($csrf, 'POST', '/api/tasks/'.$taskId.'/decision', ['decision' => 'APPROVE']);
        self::assertResponseIsSuccessful();
    }

    public function testADocumentWrittenInPalIsApprovedPublishedReadAndFound(): void
    {
        [$documentId, $revisionId] = $this->createDocument();
        $this->writeBody($revisionId, self::body('Purpose', 'Recalibrate every wrench quarterly.'));
        self::assertResponseIsSuccessful();

        $this->approve($revisionId);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseIsSuccessful();

        $this->as('reader');
        $this->client->request('GET', '/api/documents/'.$documentId);
        $body = $this->json()['current']['body'];
        self::assertSame('Purpose', $body['content'][0]['content'][0]['text']);
        self::assertNull($this->json()['current']['file']);
        // Words that appear only in the body are searchable.
        $this->client->request('GET', '/api/library?q=quarterly');
        self::assertSame(1, $this->json()['total']);

        // The approval bound the body: the manifest is not the metadata-only hash.
        $manifest = (string) $this->db->fetchOne('SELECT content_manifest_hash FROM revision WHERE id = ?', [$revisionId]);
        $stored = (string) $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]);
        self::assertSame($manifest, \App\Documents\ManifestHasher::hash('Calibration of torque tools', null, [], null, [], hash('sha256', $stored)));
    }

    public function testSubmittingNeedsContentOrAFile(): void
    {
        [, $revisionId] = $this->createDocument();
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('file', $this->json()['fields']);
    }

    public function testUnsafeContentIsRejectedAndNothingIsStored(): void
    {
        [, $revisionId] = $this->createDocument();
        $this->writeBody($revisionId, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(document.cookie)']]]],
        ]]]]);
        self::assertResponseStatusCodeSame(422);

        $this->writeBody($revisionId, ['type' => 'doc', 'content' => [['type' => 'iframe', 'attrs' => ['src' => 'https://evil.test']]]]);
        self::assertResponseStatusCodeSame(422);

        self::assertNull($this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]));
    }

    public function testBodyEditsUseOptimisticConcurrencyAndAreAudited(): void
    {
        [, $revisionId] = $this->createDocument();
        $version = $this->rowVersion($revisionId);
        $csrf = $this->as('author');
        $saved = $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::body('Scope', 'All torque tools.'), 'rowVersion' => $version]);
        self::assertResponseIsSuccessful();
        self::assertSame($version + 1, $saved['rowVersion']);

        // Saving the same content again (autosave) changes nothing.
        $again = $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::body('Scope', 'All torque tools.'), 'rowVersion' => $version + 1]);
        self::assertSame($version + 1, $again['rowVersion']);

        // A stale editor cannot overwrite newer content.
        $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::body('Scope', 'Stale.'), 'rowVersion' => $version]);
        self::assertResponseStatusCodeSame(409);

        $edits = $this->db->fetchFirstColumn("SELECT changes->'body'->>'to_sha256' FROM audit_event WHERE event_type = 'REVISION_EDITED' AND subject_id = ?", [$revisionId]);
        self::assertCount(1, $edits);
        self::assertSame(hash('sha256', (string) $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId])), $edits[0]);

        // Clearing the editor removes the body.
        $this->call($csrf, 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]], 'rowVersion' => $version + 1]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]));
    }

    public function testOnlyEditorsCanWriteTheBodyAndOnlyWhileDraft(): void
    {
        [, $revisionId] = $this->createDocument();
        $this->call($this->as('reader'), 'PUT', '/api/revisions/'.$revisionId.'/body', ['body' => self::body('X', 'Y'), 'rowVersion' => $this->rowVersion($revisionId)]);
        self::assertResponseStatusCodeSame(404);

        $this->writeBody($revisionId, self::body('Purpose', 'Text.'));
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        $this->writeBody($revisionId, self::body('Purpose', 'Changed after submission.'));
        self::assertResponseStatusCodeSame(409);
    }

    public function testTamperedApprovedBodyIsNotReleasedAndTheDatabaseRefusesDirectEdits(): void
    {
        [, $revisionId] = $this->createDocument();
        $this->writeBody($revisionId, self::body('Purpose', 'Approved text.'));
        $this->approve($revisionId);

        try {
            $this->db->executeStatement("UPDATE revision SET body_text = 'x' WHERE id = ?", [$revisionId]);
            self::fail('The immutability trigger must refuse a body change on an approved revision.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('immutable', $e->getMessage());
        }

        // Below the application: bypass the trigger and swap the body.
        $tampered = (string) json_encode(self::body('Purpose', 'Tampered text.'));
        $this->db->executeStatement('ALTER TABLE revision DISABLE TRIGGER trg_revision_immutable');
        $this->db->executeStatement('UPDATE revision SET body_json = ? WHERE id = ?', [$tampered, $revisionId]);
        $this->db->executeStatement('ALTER TABLE revision ENABLE TRIGGER trg_revision_immutable');

        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('approval_integrity_failed', $this->json()['error']);
    }

    public function testTheNextRevisionStartsFromThePublishedBody(): void
    {
        [$documentId, $revisionId] = $this->createDocument();
        $this->writeBody($revisionId, self::body('Purpose', 'Version one.'));
        $this->approve($revisionId);
        $this->call($this->as('publisher'), 'POST', '/api/revisions/'.$revisionId.'/release');
        self::assertResponseIsSuccessful();

        $next = $this->call($this->as('author'), 'POST', '/api/documents/'.$documentId.'/revisions');
        self::assertResponseIsSuccessful();
        $nextId = (string) ($next['revisionId'] ?? $next['id'] ?? '');
        self::assertSame(
            $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]),
            $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$nextId]),
        );
    }

    public function testAdministratorsMaintainTemplatesThatAuthorsStartFrom(): void
    {
        $template = self::body('Purpose', 'State why this procedure exists.');

        // Authors cannot create templates.
        $this->call($this->as('author'), 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'Standard SOP', 'body' => $template]);
        self::assertResponseStatusCodeSame(403);

        $csrf = $this->as('admin');
        $this->call($csrf, 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'Standard SOP', 'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]]]);
        self::assertResponseStatusCodeSame(422);
        $created = $this->call($csrf, 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'Standard SOP', 'description' => 'Purpose, scope, procedure.', 'body' => $template]);
        self::assertResponseStatusCodeSame(201);
        $templateId = (string) $created['id'];
        $this->call($csrf, 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'Standard SOP', 'body' => $template]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('name', $this->json()['fields']);

        // Authors see the active templates for the type they chose, and start from one.
        $this->as('author');
        $this->client->request('GET', '/api/document-types/'.$this->typeId.'/templates');
        self::assertSame(['Standard SOP'], array_column($this->json()['templates'], 'name'));
        $this->client->request('GET', '/api/document-types/'.$this->otherTypeId.'/templates');
        self::assertSame([], $this->json()['templates']);

        [, $revisionId] = $this->createDocument(['templateId' => $templateId]);
        self::assertSame(
            $this->db->fetchOne('SELECT body_json FROM document_template WHERE id = ?', [$templateId]),
            $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]),
        );
        self::assertSame($templateId, $this->db->fetchOne("SELECT changes->>'template_id' FROM audit_event WHERE event_type = 'REVISION_CREATED' AND subject_id = ?", [$revisionId]));

        // A template of another type cannot be applied.
        $this->call($this->as('author'), 'POST', '/api/documents', ['typeId' => $this->otherTypeId, 'departmentId' => $this->deptId, 'title' => 'Policy', 'templateId' => $templateId]);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('templateId', $this->json()['fields']);

        // Editing a template never changes documents already started from it.
        $csrf = $this->as('admin');
        $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['body' => self::body('Purpose', 'New guidance.'), 'rowVersion' => 1]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('State why', (string) $this->db->fetchOne('SELECT body_json FROM revision WHERE id = ?', [$revisionId]));
        $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['name' => 'Stale', 'rowVersion' => 1]);
        self::assertResponseStatusCodeSame(409);

        // Deactivated templates are neither listed nor accepted.
        $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['isActive' => false, 'rowVersion' => 2]);
        self::assertResponseIsSuccessful();
        $this->as('author');
        $this->client->request('GET', '/api/document-types/'.$this->typeId.'/templates');
        self::assertSame([], $this->json()['templates']);
        $this->call($this->as('author'), 'POST', '/api/documents', ['typeId' => $this->typeId, 'departmentId' => $this->deptId, 'title' => 'X', 'templateId' => $templateId]);
        self::assertResponseStatusCodeSame(422);

        // The admin can still read it, and every change is in the audit trail.
        $this->as('admin');
        $this->client->request('GET', '/api/admin/templates/'.$templateId);
        self::assertFalse($this->json()['isActive']);
        self::assertSame('New guidance.', $this->json()['body']['content'][1]['content'][0]['text']);
        self::assertSame(
            ['TEMPLATE_CREATED', 'TEMPLATE_UPDATED', 'TEMPLATE_UPDATED'],
            $this->db->fetchFirstColumn("SELECT event_type FROM audit_event WHERE subject_type = 'document_template' ORDER BY id"),
        );
    }

    public function testEditorsSeeWhoElseIsEditingADraft(): void
    {
        [$documentId, $revisionId] = $this->createDocument();
        // A second author on the same document.
        $this->users['coauthor'] = self::createUser($this->db, 'b0000000-0000-4000-8000-000000000006', 'Cody Coauthor');
        self::grantRole($this->db, $this->users['coauthor'], 'AUTHOR');
        $this->call($this->as('author'), 'POST', '/api/documents/'.$documentId.'/grants', ['role' => 'AUTHOR', 'userId' => $this->users['coauthor']]);
        self::assertResponseStatusCodeSame(201);

        $first = $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        self::assertResponseIsSuccessful();
        self::assertTrue($first['mine']);
        // A renewal keeps the claim and is not audited again.
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'REVISION_EDITING_STARTED'"));

        // The co-author is told who is editing; the claim does not move.
        $second = $this->call($this->as('coauthor'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        self::assertFalse($second['mine']);
        self::assertSame('Ada Author', $second['holder']['displayName']);
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertSame('Ada Author', $this->json()['openRevision']['editing']['displayName']);

        // Someone else cannot clear the claim; the holder can.
        $this->call($this->as('coauthor'), 'DELETE', '/api/revisions/'.$revisionId.'/editing');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM revision_editing'));
        $this->call($this->as('author'), 'DELETE', '/api/revisions/'.$revisionId.'/editing');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM revision_editing'));
        // Releasing and re-claiming in a loop does not flood the audit trail.
        for ($i = 0; $i < 3; ++$i) {
            $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/editing');
            $this->call($this->as('author'), 'DELETE', '/api/revisions/'.$revisionId.'/editing');
        }
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'REVISION_EDITING_STARTED' AND actor_user_id = ?", [$this->users['author']]));

        // A stale claim (closed browser) is ignored and taken over.
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        $this->db->executeStatement("UPDATE revision_editing SET started_at = now() - interval '1 hour', heartbeat_at = now() - interval '10 minutes'");
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertNull($this->json()['openRevision']['editing']);
        self::assertTrue($this->call($this->as('coauthor'), 'POST', '/api/revisions/'.$revisionId.'/editing')['mine']);

        // Readers cannot claim a draft, and nothing can be claimed once it is submitted.
        $this->call($this->as('reader'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        self::assertResponseStatusCodeSame(404);
        $this->writeBody($revisionId, self::body('Purpose', 'Text.'));
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/submit', ['approverId' => $this->users['approver']]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM revision_editing'), 'Submitting clears the editing claim.');
        $this->call($this->as('author'), 'POST', '/api/revisions/'.$revisionId.'/editing');
        self::assertResponseStatusCodeSame(409);
    }

    public function testTemplatesKeepEveryVersionAndDocumentsRecordWhichOneTheyStartedFrom(): void
    {
        $csrf = $this->as('admin');
        $created = $this->call($csrf, 'POST', '/api/admin/templates', ['documentTypeId' => $this->typeId, 'name' => 'SOP', 'body' => self::body('Purpose', 'Wording one.')]);
        self::assertSame(1, $created['version']);
        $templateId = (string) $created['id'];

        [, $firstRevision] = $this->createDocument(['templateId' => $templateId]);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT template_version FROM revision WHERE id = ?', [$firstRevision]));

        // A content change is a new version; activation alone is not.
        $csrf = $this->as('admin');
        $updated = $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['body' => self::body('Purpose', 'Wording two.'), 'rowVersion' => 1]);
        self::assertSame(2, $updated['version']);
        $toggled = $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['isActive' => false, 'rowVersion' => 2]);
        self::assertSame(2, $toggled['version']);
        $this->call($csrf, 'PATCH', '/api/admin/templates/'.$templateId, ['isActive' => true, 'name' => 'Standard SOP', 'rowVersion' => 3]);

        $this->client->request('GET', '/api/admin/templates/'.$templateId.'/versions');
        $versions = $this->json()['versions'];
        self::assertSame([3, 2, 1], array_column($versions, 'version'));
        self::assertSame(['Standard SOP', 'SOP', 'SOP'], array_column($versions, 'name'));
        $this->client->request('GET', '/api/admin/templates/'.$templateId.'/versions/1');
        self::assertSame('Wording one.', $this->json()['body']['content'][1]['content'][0]['text']);
        $this->client->request('GET', '/api/admin/templates/'.$templateId.'/versions/9');
        self::assertResponseStatusCodeSame(404);

        // New documents start from the current version and say so.
        [$documentId, $secondRevision] = $this->createDocument(['templateId' => $templateId]);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT template_version FROM revision WHERE id = ?', [$secondRevision]));
        $this->as('author');
        $this->client->request('GET', '/api/documents/'.$documentId);
        self::assertSame(['id' => $templateId, 'name' => 'Standard SOP', 'version' => 3], $this->json()['openRevision']['template']);

        // History is evidence: the database refuses to change or remove a version.
        foreach (["UPDATE document_template_version SET name = 'x' WHERE template_id = ?", 'DELETE FROM document_template_version WHERE template_id = ?'] as $sql) {
            try {
                $this->db->executeStatement($sql, [$templateId]);
                self::fail('Template versions must be immutable.');
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertStringContainsString('immutable', $e->getMessage());
            }
        }

        // Only template administrators can read the history.
        $this->as('author');
        $this->client->request('GET', '/api/admin/templates/'.$templateId.'/versions');
        self::assertResponseStatusCodeSame(403);
    }

    public function testReadersCannotSeeTheTemplateAdministration(): void
    {
        $this->as('reader');
        $this->client->request('GET', '/api/admin/templates');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/document-types/'.$this->typeId.'/templates');
        self::assertResponseStatusCodeSame(403);
    }
}
