import { useRef, useState } from 'react';
import { Download, Paperclip, Upload } from 'lucide-react';
import {
  useAttachments,
  useDownloadAttachment,
  useUploadAttachment,
} from '@/hooks/useAttachments';
import { getErrorMessage } from '@/lib/api';
import { formatBytes, formatDateTime } from '@/lib/format';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import type { AttachmentRecord } from '@/types';
import { UserName } from './UserName';

export interface AttachmentsPanelProps {
  entityType: string;
  entityId?: string | number | null;
  /** Page key used to gate the upload control via usePagePerms().canEdit. */
  canEditPage: string;
}

export function AttachmentsPanel({
  entityType,
  entityId,
  canEditPage,
}: AttachmentsPanelProps) {
  const { notify } = useToast();
  const { canEdit } = usePagePerms();
  const { data, isLoading } = useAttachments(entityType, entityId);
  const upload = useUploadAttachment();
  const download = useDownloadAttachment();
  const fileRef = useRef<HTMLInputElement>(null);
  const [selected, setSelected] = useState<File | null>(null);
  const [progress, setProgress] = useState(0);

  const canUpload = canEdit(canEditPage);

  const handleUpload = async () => {
    if (!selected || entityId == null) return;
    setProgress(0);
    try {
      await upload.mutateAsync({
        entityType,
        entityId,
        file: selected,
        onProgress: setProgress,
      });
      notify('File uploaded', 'success');
      setSelected(null);
      if (fileRef.current) fileRef.current.value = '';
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const handleDownload = async (a: AttachmentRecord) => {
    try {
      await download(a);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <div className="stack" style={{ gap: 12 }}>
      {isLoading ? (
        <div className="loading-block" style={{ minHeight: 60 }}>
          <span className="spinner" />
        </div>
      ) : data && data.length > 0 ? (
        <div className="stack" style={{ gap: 8 }}>
          {data.map((a) => (
            <div key={a.id} className="attachment-row">
              <Paperclip size={15} className="attachment-row__icon" />
              <div className="attachment-row__meta">
                <div className="attachment-row__name">{a.original_filename}</div>
                <div className="muted text-sm">
                  {formatBytes(a.size_bytes)}
                  {a.uploaded_by != null && (
                    <>
                      {' · '}
                      <UserName id={a.uploaded_by} />
                    </>
                  )}
                  {a.created_at && <> · {formatDateTime(a.created_at)}</>}
                </div>
              </div>
              <button
                type="button"
                className="btn btn-sm"
                onClick={() => handleDownload(a)}
              >
                <Download size={14} /> Download
              </button>
            </div>
          ))}
        </div>
      ) : (
        <div className="empty-state-sm">No attachments uploaded.</div>
      )}

      {canUpload && (
        <div className="attachment-upload">
          <input
            ref={fileRef}
            type="file"
            className="attachment-upload__input"
            onChange={(e) => setSelected(e.target.files?.[0] ?? null)}
            disabled={upload.isPending}
          />
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleUpload}
            disabled={!selected || upload.isPending}
          >
            <Upload size={14} />
            {upload.isPending ? `Uploading ${progress}%` : 'Upload'}
          </button>
        </div>
      )}
    </div>
  );
}

/** Card wrapper matching the detail-page section pattern. */
export function AttachmentsPanelCard(props: AttachmentsPanelProps) {
  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title row" style={{ gap: 8 }}>
          <Paperclip size={15} /> Attachments
        </div>
      </div>
      <div className="card__body">
        <AttachmentsPanel {...props} />
      </div>
    </div>
  );
}
