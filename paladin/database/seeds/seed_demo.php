<?php
/**
 * Demo seed data for the PALADIN platform — workflow templates, starter spaces,
 * pages, controlled documents, processes, tasks and the standard template
 * library. Invoked once on a fresh install. Idempotent-ish: guarded by the
 * fresh-install check in install.php.
 */
function seed_demo(): void {
    $admin = Database::fetchOne("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
    $adminId = (int)($admin['id'] ?? 1);

    // ── Demo users (one per representative role) ──────────────────────────
    $pw = Security::hashPassword('PalDemo!2026');
    $demoUsers = [
        ['Pat Chen',      'pal.admin@demo.local',        'pal_admin',        'Knowledge Manager',     'Operations'],
        ['Dana Reyes',    'compliance@demo.local',       'compliance_admin', 'Compliance Lead',       'Compliance'],
        ['Sam Okafor',    'owner@demo.local',            'space_owner',      'Quality Manager',       'Quality'],
        ['Riley Nguyen',  'author@demo.local',           'contributor',      'Process Analyst',       'Operations'],
        ['Jordan Blake',  'reviewer@demo.local',         'reviewer',         'Senior Reviewer',       'Quality'],
        ['Morgan Diaz',   'approver@demo.local',         'approver',         'Director of Quality',   'Quality'],
        ['Casey Lee',     'auditor@demo.local',          'auditor',          'Internal Auditor',      'Audit'],
        ['Taylor Kim',    'viewer@demo.local',           'viewer',           'Staff',                 'Operations'],
    ];
    $userIds = [];
    foreach ($demoUsers as $u) {
        $id = Database::insert('users', [
            'name' => $u[0], 'email' => $u[1], 'password_hash' => $pw,
            'role' => $u[2], 'title' => $u[3], 'department' => $u[4],
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        $userIds[$u[2]] = $id;
    }
    $ownerId  = $userIds['space_owner'] ?? $adminId;
    $authorId = $userIds['contributor'] ?? $adminId;
    $apprId   = $userIds['approver'] ?? $adminId;
    $revId    = $userIds['reviewer'] ?? $adminId;

    // ── Workflow templates ────────────────────────────────────────────────
    $policyWf = Database::insert('workflow_templates', [
        'name' => 'Policy Approval (2-stage)', 'description' => 'Reviewer then approver sign-off for policies.',
        'workflow_type' => 'policy', 'approval_mode' => 'sequential', 'created_by' => $adminId,
    ]);
    Database::insert('workflow_steps', ['template_id' => $policyWf, 'step_number' => 1, 'name' => 'Quality Review', 'approver_role' => 'reviewer', 'sla_hours' => 48]);
    Database::insert('workflow_steps', ['template_id' => $policyWf, 'step_number' => 2, 'name' => 'Management Approval', 'approver_role' => 'approver', 'sla_hours' => 72]);

    $procWf = Database::insert('workflow_templates', [
        'name' => 'Procedure Approval', 'description' => 'Single approver for controlled procedures.',
        'workflow_type' => 'procedure', 'approval_mode' => 'single', 'created_by' => $adminId,
    ]);
    Database::insert('workflow_steps', ['template_id' => $procWf, 'step_number' => 1, 'name' => 'Approval', 'approver_role' => 'approver', 'sla_hours' => 72]);

    $changeWf = Database::insert('workflow_templates', [
        'name' => 'Change Request Review', 'description' => 'Parallel review for change requests.',
        'workflow_type' => 'change', 'approval_mode' => 'parallel', 'created_by' => $adminId,
    ]);
    Database::insert('workflow_steps', ['template_id' => $changeWf, 'step_number' => 1, 'name' => 'Quality', 'approver_role' => 'reviewer', 'sla_hours' => 48]);
    Database::insert('workflow_steps', ['template_id' => $changeWf, 'step_number' => 2, 'name' => 'Management', 'approver_role' => 'approver', 'sla_hours' => 48]);

    // ── Spaces ────────────────────────────────────────────────────────────
    $spaces = [
        ['QMS',  'Quality Management System', 'Controlled procedures, work instructions and quality records.', 'compliance', 'bi-patch-check-fill', '#2563eb', $ownerId],
        ['HR',   'Human Resources',           'People policies, onboarding and employee handbook.',            'department', 'bi-people-fill',      '#8b5cf6', $ownerId],
        ['SEC',  'Information Security',       'Security policies, standards and evidence.',                    'compliance', 'bi-shield-lock-fill', '#dc2626', $userIds['compliance_admin'] ?? $adminId],
        ['OPS',  'Operations',                'Operational processes and runbooks.',                           'process',    'bi-gear-wide-connected', '#059669', $ownerId],
    ];
    $spaceIds = [];
    foreach ($spaces as $s) {
        $sid = Database::insert('spaces', [
            'space_key' => $s[0], 'name' => $s[1], 'description' => $s[2], 'type' => $s[3],
            'icon' => $s[4], 'color' => $s[5], 'owner_id' => $s[6], 'created_by' => $adminId,
        ]);
        $spaceIds[$s[0]] = $sid;
        Database::insert('space_members', ['space_id' => $sid, 'user_id' => $s[6], 'role' => 'owner']);
    }

    // ── Pages ─────────────────────────────────────────────────────────────
    $welcome = Database::insert('pages', [
        'space_id' => $spaceIds['QMS'], 'title' => 'QMS Home',
        'body' => '<h2>Welcome to the Quality Management System</h2><p>This space is the authoritative source for our controlled procedures, work instructions and quality records. Use the navigation tree to browse, or search across the library from the top bar.</p><ul><li><strong>Procedures</strong> — how we do controlled work</li><li><strong>Work Instructions</strong> — step-by-step task guidance</li><li><strong>Records</strong> — evidence of conformance</li></ul>',
        'status' => 'published', 'owner_id' => $ownerId, 'created_by' => $ownerId, 'published_at' => date('Y-m-d H:i:s'),
    ]);
    Database::insert('page_versions', ['page_id' => $welcome, 'version' => 1, 'title' => 'QMS Home', 'body' => 'Initial version', 'change_note' => 'Created', 'edited_by' => $ownerId]);
    Database::insert('pages', [
        'space_id' => $spaceIds['QMS'], 'parent_id' => $welcome, 'title' => 'Document Control Overview',
        'body' => '<p>All controlled documents follow the lifecycle: <em>Draft → In Review → Approved → Published</em>, with revision tracking and acknowledgement capture.</p>',
        'status' => 'published', 'owner_id' => $ownerId, 'created_by' => $authorId, 'published_at' => date('Y-m-d H:i:s'),
    ]);
    Database::insert('pages', [
        'space_id' => $spaceIds['SEC'], 'title' => 'Security Program Overview',
        'body' => '<h2>Information Security Program</h2><p>Policies, standards and supporting evidence for our security program.</p>',
        'status' => 'published', 'owner_id' => $userIds['compliance_admin'] ?? $adminId, 'created_by' => $adminId, 'published_at' => date('Y-m-d H:i:s'),
    ]);

    // ── Controlled documents ──────────────────────────────────────────────
    $docs = [
        ['POL-0001', 'Information Security Policy', 'policy', 'SEC', 'confidential', '2.1', 'published', true],
        ['PRC-0001', 'Document Control Procedure', 'procedure', 'QMS', 'internal', '3.0', 'published', true],
        ['WI-0001',  'New Supplier Onboarding Work Instruction', 'work_instruction', 'OPS', 'internal', '1.2', 'approved', false],
        ['POL-0002', 'Acceptable Use Policy', 'policy', 'SEC', 'internal', '1.0', 'in_review', true],
        ['FRM-0001', 'Corrective Action Request Form', 'form', 'QMS', 'internal', '1.0', 'published', false],
        ['STD-0001', 'Data Classification Standard', 'standard', 'SEC', 'confidential', '1.4', 'draft', false],
    ];
    foreach ($docs as $d) {
        $effective = $d[6] === 'published' ? date('Y-m-d', strtotime('-30 days')) : null;
        $review    = $d[6] === 'published' ? date('Y-m-d', strtotime('+11 months')) : null;
        $expire    = $d[6] === 'published' ? date('Y-m-d', strtotime('+1 year')) : null;
        Database::insert('documents', [
            'document_code' => $d[0], 'title' => $d[1], 'doc_type' => $d[2],
            'space_id' => $spaceIds[$d[3]] ?? null, 'owner_id' => $ownerId,
            'reviewer_id' => $revId, 'approver_id' => $apprId,
            'department' => 'Quality', 'business_unit' => 'Corporate',
            'classification' => $d[4], 'revision' => $d[5], 'status' => $d[6],
            'description' => $d[1] . ' — controlled document maintained under the QMS.',
            'effective_date' => $effective, 'review_date' => $review, 'expiration_date' => $expire,
            'requires_ack' => $d[7] ? 't' : 'f',
            'created_by' => $authorId,
            'published_at' => $d[6] === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    // ── Processes ─────────────────────────────────────────────────────────
    Database::insert('processes', [
        'process_code' => 'PRO-0001', 'name' => 'Supplier Qualification', 'description' => 'End-to-end process for qualifying and onboarding new suppliers.',
        'space_id' => $spaceIds['OPS'], 'owner_id' => $ownerId, 'department' => 'Procurement',
        'status' => 'published', 'version' => '2.0', 'created_by' => $authorId,
    ]);
    Database::insert('processes', [
        'process_code' => 'PRO-0002', 'name' => 'Incident Response', 'description' => 'Security incident detection, containment, eradication and recovery.',
        'space_id' => $spaceIds['SEC'], 'owner_id' => $userIds['compliance_admin'] ?? $adminId, 'department' => 'Security',
        'status' => 'published', 'version' => '1.1', 'created_by' => $adminId,
    ]);

    // ── Tasks ─────────────────────────────────────────────────────────────
    $tasks = [
        ['Review Acceptable Use Policy', 'review', 'open', 'high', $revId, date('Y-m-d', strtotime('+3 days'))],
        ['Annual review of Information Security Policy', 'review', 'open', 'medium', $ownerId, date('Y-m-d', strtotime('+20 days'))],
        ['Update Data Classification Standard draft', 'task', 'in_progress', 'medium', $authorId, date('Y-m-d', strtotime('+7 days'))],
        ['Corrective action: supplier audit finding', 'corrective_action', 'open', 'urgent', $ownerId, date('Y-m-d', strtotime('-2 days'))],
    ];
    foreach ($tasks as $t) {
        Database::insert('tasks', [
            'title' => $t[0], 'type' => $t[1], 'status' => $t[2], 'priority' => $t[3],
            'assigned_to' => $t[4], 'created_by' => $adminId, 'due_date' => $t[5],
        ]);
    }

    // ── Template library ──────────────────────────────────────────────────
    $templates = [
        ['Policy Template', 'Standard corporate policy structure.', 'document', 'policy',
         '<h1>Policy Title</h1><h2>1. Purpose</h2><p>...</p><h2>2. Scope</h2><p>...</p><h2>3. Policy Statements</h2><p>...</p><h2>4. Roles &amp; Responsibilities</h2><p>...</p><h2>5. Compliance</h2><p>...</p><h2>6. Revision History</h2><p>...</p>'],
        ['Procedure Template', 'Step-by-step controlled procedure.', 'document', 'procedure',
         '<h1>Procedure Title</h1><h2>Purpose</h2><p>...</p><h2>Scope</h2><p>...</p><h2>Definitions</h2><p>...</p><h2>Procedure Steps</h2><ol><li>...</li></ol><h2>Records</h2><p>...</p>'],
        ['Work Instruction Template', 'Task-level instructions.', 'document', 'work_instruction',
         '<h1>Work Instruction</h1><h2>Task</h2><p>...</p><h2>Required Tools</h2><p>...</p><h2>Steps</h2><ol><li>...</li></ol><h2>Safety / Quality Notes</h2><p>...</p>'],
        ['Process Template', 'Process definition.', 'process', 'process',
         '<h1>Process Name</h1><h2>Trigger</h2><p>...</p><h2>Inputs</h2><p>...</p><h2>Activities</h2><p>...</p><h2>Outputs</h2><p>...</p><h2>Owner &amp; Metrics</h2><p>...</p>'],
        ['Meeting Notes Template', 'Meeting minutes.', 'meeting', null,
         '<h1>Meeting Notes</h1><p><strong>Date:</strong> ... <strong>Attendees:</strong> ...</p><h2>Agenda</h2><ul><li>...</li></ul><h2>Decisions</h2><p>...</p><h2>Action Items</h2><p>...</p>'],
        ['Risk Assessment Template', 'Risk evaluation worksheet.', 'risk', null,
         '<h1>Risk Assessment</h1><h2>Context</h2><p>...</p><h2>Identified Risks</h2><p>...</p><h2>Likelihood &amp; Impact</h2><p>...</p><h2>Treatment</h2><p>...</p>'],
        ['Audit Template', 'Internal audit plan & checklist.', 'audit', null,
         '<h1>Audit Plan</h1><h2>Scope</h2><p>...</p><h2>Criteria</h2><p>...</p><h2>Checklist</h2><p>...</p><h2>Findings</h2><p>...</p>'],
        ['Project Charter Template', 'Project kickoff.', 'project', null,
         '<h1>Project Charter</h1><h2>Objective</h2><p>...</p><h2>Scope</h2><p>...</p><h2>Stakeholders</h2><p>...</p><h2>Milestones</h2><p>...</p>'],
    ];
    foreach ($templates as $t) {
        Database::insert('templates', [
            'name' => $t[0], 'description' => $t[1], 'category' => $t[2], 'doc_type' => $t[3],
            'body' => $t[4], 'created_by' => $adminId,
        ]);
    }

    // ── Tags ──────────────────────────────────────────────────────────────
    foreach ([['ISO 9001', '#2563eb'], ['SOC 2', '#8b5cf6'], ['Confidential', '#dc2626'], ['HR', '#059669'], ['Onboarding', '#d97706']] as $tag) {
        Database::insert('tags', ['name' => $tag[0], 'color' => $tag[1]]);
    }
}
