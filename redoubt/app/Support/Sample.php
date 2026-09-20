<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Optional sample-data loader used by first-run setup. Populates one program with
 * realistic content across every module so the portal is immediately usable and
 * demonstrable — using only the database, no external API. It creates content and
 * companies, not extra login accounts (the admin onboards people as needed).
 */
final class Sample
{
    public static function load(int $pid, int $adminId): void
    {
        $gmre = Db::insert('company', ['name' => 'GMRE', 'kind' => 'prime']);
        $acme = Db::insert('company', ['name' => 'Acme Aerospace', 'kind' => 'sub']);
        $beta = Db::insert('company', ['name' => 'Beta Defense', 'kind' => 'sub']);

        // Announcements
        Db::insert('announcement', ['program_id' => $pid, 'title' => 'Program kickoff Monday 0900', 'body' => 'All-hands kickoff on the program bridge. Agenda attached in Documents.', 'audience' => ['all'], 'priority' => 'high', 'publish_at' => date('c'), 'created_by' => $adminId]);
        Db::insert('announcement', ['program_id' => $pid, 'title' => 'Internal: staffing plan review', 'body' => 'Execution team staffing review Thursday.', 'audience' => ['internal'], 'priority' => 'normal', 'publish_at' => date('c'), 'created_by' => $adminId]);
        Db::insert('announcement', ['program_id' => $pid, 'title' => 'Draft: award-fee narrative', 'body' => 'Draft for internal review before posting.', 'audience' => ['all'], 'priority' => 'normal', 'created_by' => $adminId]);

        // Documents (metadata + zone/markings; local upload/Graph optional)
        Db::insert('document_ref', ['program_id' => $pid, 'zone' => 'project', 'company_scope' => [], 'title' => 'Program Management Plan (PMP)', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $pid, 'zone' => 'contracts', 'company_scope' => [], 'title' => 'Statement of Work (SOW)', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $pid, 'zone' => 'company', 'company_scope' => [$acme], 'title' => 'Acme subcontract package', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $pid, 'zone' => 'project', 'company_scope' => [], 'export_controlled' => true, 'cui_marked' => true, 'title' => 'ITAR technical data package', 'updated_at' => date('c')]);
        Db::insert('document_ref', ['program_id' => $pid, 'zone' => 'customer', 'company_scope' => [], 'title' => 'CDRL A001 — Monthly Status Report', 'updated_at' => date('c')]);

        // Task orders
        $to1 = Db::insert('task_order', ['program_id' => $pid, 'number' => 'TO-0001', 'title' => 'Systems Engineering Support', 'status' => 'awarded', 'company_scope' => [$acme]]);
        Db::insert('task_order', ['program_id' => $pid, 'number' => 'TO-0002', 'title' => 'Logistics & Sustainment', 'status' => 'active', 'company_scope' => [$beta]]);
        Db::insert('task_order', ['program_id' => $pid, 'number' => 'TO-0003', 'title' => 'Program Management Office', 'status' => 'draft', 'company_scope' => []]);

        // Jobs (program-wide / company / contract targeting)
        Db::insert('job_requisition', ['program_id' => $pid, 'title' => 'Systems Engineer', 'audience' => ['all'], 'company_scope' => [], 'task_order_scope' => [], 'ats_url' => 'https://careers.example.us/1', 'status' => 'open']);
        Db::insert('job_requisition', ['program_id' => $pid, 'title' => 'Cleared Software Engineer (Acme)', 'audience' => [], 'company_scope' => [$acme], 'task_order_scope' => [], 'ats_url' => 'https://careers.example.us/2', 'status' => 'open']);
        Db::insert('job_requisition', ['program_id' => $pid, 'title' => 'Logistics Analyst (TO-0001)', 'audience' => [], 'company_scope' => [], 'task_order_scope' => [$to1], 'ats_url' => 'https://careers.example.us/3', 'status' => 'open']);
        Db::insert('job_requisition', ['program_id' => $pid, 'title' => 'Program Analyst', 'audience' => ['all'], 'company_scope' => [], 'task_order_scope' => [], 'status' => 'draft']);

        // Directory
        Db::insert('program_contact', ['program_id' => $pid, 'freeform' => 'Program Manager', 'role_label' => 'PM', 'company_id' => $gmre, 'visibility' => ['all']]);
        Db::insert('program_contact', ['program_id' => $pid, 'freeform' => 'Contracts Lead', 'role_label' => 'Contracts', 'company_id' => $gmre, 'visibility' => ['all']]);
        Db::insert('program_contact', ['program_id' => $pid, 'freeform' => 'Facility Security Officer', 'role_label' => 'Security', 'company_id' => $gmre, 'visibility' => ['internal']]);

        // A pending onboarding request
        Db::insert('access_request', ['program_id' => $pid, 'email' => 'jordan@acme.us', 'display_name' => 'Jordan Reyes', 'company_id' => $acme, 'requested_role' => 'sub_member', 'kind' => 'external', 'is_us_person' => true, 'justification' => 'New subcontractor engineer', 'status' => 'pending', 'sponsor_id' => $adminId]);

        Settings::saveBranding($pid, ['displayName' => 'GMRE — Falcon Program', 'accent' => '#1e6fb8', 'logoUrl' => null], $adminId);
        Audit::log('setup.sample_loaded', 'program#' . $pid, $pid, $adminId);
    }
}
