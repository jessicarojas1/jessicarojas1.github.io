import { ActivityTimelineCard } from './ActivityTimeline';
import { AttachmentsPanelCard } from './AttachmentsPanel';
import { CommentsPanelCard } from './CommentsPanel';

export interface RecordSupplementsProps {
  /** Backend audit-log / attachment entity_type for this module. */
  entityType: string;
  /** Record id from the route / detail data. */
  entityId?: string | number | null;
  /** Page key used to gate the upload control (usePagePerms().canEdit). */
  canEditPage: string;
}

/**
 * Bottom-of-page enterprise sections shared by every record detail view:
 * a per-record Activity timeline (from the immutable audit log) and an
 * Attachments/Evidence panel (upload / list / download).
 */
export function RecordSupplements({
  entityType,
  entityId,
  canEditPage,
}: RecordSupplementsProps) {
  return (
    <div className="detail-grid" style={{ marginTop: 'var(--space-4)' }}>
      <ActivityTimelineCard entityType={entityType} entityId={entityId} />
      <AttachmentsPanelCard
        entityType={entityType}
        entityId={entityId}
        canEditPage={canEditPage}
      />
      <CommentsPanelCard
        entityType={entityType}
        entityId={entityId}
        canEditPage={canEditPage}
      />
    </div>
  );
}
