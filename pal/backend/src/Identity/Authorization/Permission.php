<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

/**
 * Granular, organisation-wide permissions checked with isGranted(Permission::X).
 * Document-scoped permissions (editing, deciding, reading restricted documents) are
 * evaluated against access grants and arrive with the document modules in Phase 2+.
 */
final class Permission
{
    public const LIBRARY_READ = 'library.read';
    public const DOCUMENT_CREATE = 'document.create';
    public const DOCUMENT_CHANGE_OWNER = 'document.change_owner';
    public const DOCUMENT_OBSOLETE = 'document.obsolete';
    public const DOCUMENT_ARCHIVE = 'document.archive';
    public const DOCUMENT_LEGAL_HOLD = 'document.legal_hold';
    public const WORKFLOW_REASSIGN = 'workflow.reassign';
    public const WORKFLOW_CANCEL = 'workflow.cancel';
    public const PUBLICATION_SCHEDULE = 'publication.schedule';
    public const PUBLICATION_RELEASE = 'publication.release';
    public const PUBLICATION_EMERGENCY = 'publication.emergency';
    public const ADMIN_TYPES = 'admin.types';
    public const ADMIN_NUMBERING = 'admin.numbering';
    public const ADMIN_RETENTION = 'admin.retention';
    public const ADMIN_WORKFLOWS = 'admin.workflows';
    public const ADMIN_ROLES = 'admin.roles';
    public const ADMIN_DIRECTORY = 'admin.directory';
    public const AUDIT_READ = 'audit.read';
    public const AUDIT_EXPORT = 'audit.export';
    public const REPORT_EXPORT = 'report.export';
    public const SYSTEM_CONFIG = 'system.config';

    public const ALL = [
        self::LIBRARY_READ, self::DOCUMENT_CREATE, self::DOCUMENT_CHANGE_OWNER, self::DOCUMENT_OBSOLETE,
        self::DOCUMENT_ARCHIVE, self::DOCUMENT_LEGAL_HOLD, self::WORKFLOW_REASSIGN, self::WORKFLOW_CANCEL,
        self::PUBLICATION_SCHEDULE, self::PUBLICATION_RELEASE, self::PUBLICATION_EMERGENCY, self::ADMIN_TYPES,
        self::ADMIN_NUMBERING, self::ADMIN_RETENTION, self::ADMIN_WORKFLOWS, self::ADMIN_ROLES,
        self::ADMIN_DIRECTORY, self::AUDIT_READ, self::AUDIT_EXPORT, self::REPORT_EXPORT, self::SYSTEM_CONFIG,
    ];
}
