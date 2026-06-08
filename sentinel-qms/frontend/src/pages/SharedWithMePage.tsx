import { Link } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import { SHARE_PDF_TYPES, shareLink, useDeleteShare, useMyShares } from '@/hooks';
import { getErrorMessage } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import { PageHeader } from '@/components/PageHeader';
import { PdfButton } from '@/components/PdfButton';
import { EmptyState } from '@/components/EmptyState';
import { UserName } from '@/components/UserName';

export default function SharedWithMePage() {
  const { data, isLoading, error } = useMyShares();
  const remove = useDeleteShare();

  return (
    <>
      <PageHeader
        title="Shared with Me"
        subtitle="Records colleagues have shared with you for review (read-only, in-app)."
        breadcrumbs={[{ label: 'Shared with Me' }]}
      />
      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : data.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="Nothing shared yet" description="When someone shares a record with you, it appears here." /></div></div>
      ) : (
        <div className="card">
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>Record</th><th>Type</th><th>Shared by</th><th>Note</th><th>When</th><th aria-label="actions" /></tr>
              </thead>
              <tbody>
                {data.map((s) => {
                  const link = shareLink(s);
                  return (
                    <tr key={s.id}>
                      <td>{link ? <Link to={link} className="link-btn">{s.label}</Link> : s.label}</td>
                      <td>{humanize(s.entity_type)}</td>
                      <td><UserName id={s.shared_by_user_id} /></td>
                      <td>{s.note ?? '—'}</td>
                      <td>{formatDate(s.created_at ?? null)}</td>
                      <td className="row" style={{ gap: 6 }}>
                        {SHARE_PDF_TYPES.has(s.entity_type) && (
                          <PdfButton path={`/shares/${s.id}/pdf`} filename={`${s.label}.pdf`} label="PDF" />
                        )}
                        <button type="button" className="btn btn-icon btn-ghost" aria-label="Remove" onClick={() => remove.mutate(s.id)}>
                          <Trash2 size={15} />
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
