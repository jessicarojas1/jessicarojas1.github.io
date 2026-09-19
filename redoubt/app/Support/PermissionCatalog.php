<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * The catalog of modules and their granular actions. Drives the IAM console's
 * per-module accordions and is the canonical list of assignable permission keys.
 * Keep in sync with Roles::DEFAULTS (role defaults reference these keys).
 */
final class PermissionCatalog
{
    /**
     * @return array<string,array{label:string,icon:string,actions:array<string,string>}>
     *         module key => [label, icon(emoji), actions(permKey => label)]
     */
    public static function modules(): array
    {
        return [
            'announcement' => ['label' => 'Announcements', 'icon' => '📣', 'actions' => [
                'announcement.view' => 'View', 'announcement.create' => 'Create',
                'announcement.edit' => 'Edit', 'announcement.publish' => 'Publish',
            ]],
            'document' => ['label' => 'Documents', 'icon' => '📁', 'actions' => [
                'document.view' => 'View', 'document.create' => 'Upload',
                'document.edit' => 'Edit', 'document.view.customer' => 'View (customer zone)',
            ]],
            'taskorder' => ['label' => 'Task Orders', 'icon' => '📄', 'actions' => [
                'taskorder.view' => 'View', 'taskorder.create' => 'Create',
                'taskorder.edit' => 'Edit', 'taskorder.publish' => 'Publish', 'taskorder.approve' => 'Approve',
            ]],
            'job' => ['label' => 'Job Requisitions', 'icon' => '💼', 'actions' => [
                'job.view' => 'View', 'job.create' => 'Create', 'job.edit' => 'Edit', 'job.publish' => 'Publish',
            ]],
            'directory' => ['label' => 'Directory', 'icon' => '👥', 'actions' => [
                'directory.view' => 'View', 'contact.manage' => 'Manage contacts',
            ]],
            'resources' => ['label' => 'Resources', 'icon' => '🔗', 'actions' => [
                'quicklink.manage' => 'Manage quick links', 'faq.manage' => 'Manage FAQ',
                'milestone.manage' => 'Manage milestones',
            ]],
            'finance' => ['label' => 'Financial', 'icon' => '💲', 'actions' => [
                'finance.view' => 'View financials',
            ]],
            'iam' => ['label' => 'Access & Security', 'icon' => '🔐', 'actions' => [
                'access.view' => 'View access', 'access.grant' => 'Grant/modify access',
                'access.revoke' => 'Revoke access', 'access.review' => 'Run access reviews',
                'export.gate.manage' => 'Manage export (US-person) gate', 'audit.view' => 'View audit log',
            ]],
            'program' => ['label' => 'Program Administration', 'icon' => '⚙️', 'actions' => [
                'program.config' => 'Program configuration', 'module.toggle' => 'Enable/disable modules',
                'branding.manage' => 'Manage branding', 'integration.config' => 'Configure integrations',
            ]],
        ];
    }

    /** Flat list of every assignable granular permission key. @return string[] */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::modules() as $mod) {
            foreach (array_keys($mod['actions']) as $k) {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    public static function total(): int
    {
        return count(self::allKeys());
    }
}
