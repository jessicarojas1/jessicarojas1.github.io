import { useState } from 'react';
import { useParams } from 'react-router-dom';
import {
  ArrowRight,
  Ban,
  CheckCircle2,
  FileText,
  PencilLine,
  RotateCcw,
} from 'lucide-react';
import { documentHooks } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { getErrorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { useToast } from '@/lib/toast';
import { PrintButton } from '@/components/PrintButton';
import { DataList, DetailState } from '@/components/detail';
import { RecordDetailHeader } from '@/components/RecordDetailHeader';
import { RecordSupplements } from '@/components/RecordSupplements';
import { UserName } from '@/components/UserName';
import { DocumentFormModal } from './DocumentFormModal';
import {
  WORKFLOW_STAGES,
  departmentLabel,
  docTypeLabel,
} from './documentOptions';
import type { ControlledDocument, DocumentTransitionAction } from '@/types';

const TEMPLATE_SECTIONS: { key: keyof ControlledDocument; label: string }[] = [
  { key: 'purpose', label: 'Purpose' },
  { key: 'scope', label: 'Scope' },
  { key: 'definitions', label: 'Definitions' },
  { key: 'responsibilities', label: 'Responsibilities' },
  { key: 'detail', label: 'Detail' },
  { key: 'revision_history', label: 'Revision History' },
  { key: 'appendix', label: 'Appendix' },
];

function StageStepper({ status }: { status: string }) {
  // Index of the current stage; -1 if obsolete (terminal, off the linear path).
  const activeIdx = WORKFLOW_STAGES.findIndex((s) => s.value === status);
  return (
    <div className="row" style={{ gap: 4, flexWrap: 'wrap', alignItems: 'center' }}>
      {WORKFLOW_STAGES.map((stage, i) => {
        const done = activeIdx > -1 && i < activeIdx;
        const current = i === activeIdx;
        const tone = done ? 'success' : current ? 'primary' : 'neutral';
        return (
          <span key={stage.value} className="row" style={{ gap: 4, alignItems: 'center' }}>
            <span
              className={`badge badge--${tone}${current ? '' : ' badge--no-dot'}`}
              aria-current={current ? 'step' : undefined}
            >
              {stage.label}
            </span>
            {i < WORKFLOW_STAGES.length - 1 && (
              <ArrowRight size={14} style={{ color: 'var(--text-muted, #888)' }} aria-hidden />
            )}
          </span>
        );
      })}
    </div>
  );
}

export default function DocumentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { notify } = useToast();
  const { canEdit } = usePagePerms();
  const { data: doc, isLoading, error } = documentHooks.useDetail(id);
  const transition = documentHooks.useAction<{ action: DocumentTransitionAction }>('transition');
  const [editOpen, setEditOpen] = useState(false);

  const mayEdit = canEdit('documents');
  const status = doc?.status;
  const canAdvance =
    status === 'concept' || status === 'work_in_progress' || status === 'peer_review';
  const canApprove = status === 'qa_review';
  const canRevise = status === 'approved';
  const isTerminal = status === 'obsolete';

  const runTransition = async (action: DocumentTransitionAction, msg: string) => {
    if (!id) return;
    try {
      await transition.mutateAsync({ id, payload: { action } });
      notify(msg, 'success');
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <DetailState
      loading={isLoading}
      error={error ? getErrorMessage(error) : null}
      notFound={!isLoading && !error && !doc}
    >
      {doc && (
        <>
          <RecordDetailHeader
            icon={<FileText size={22} />}
            recordNumber={doc.document_number}
            status={doc.status}
            title={`${doc.title} · Rev ${doc.current_revision ?? '—'}`}
            listLabel="Documents"
            listTo="/documents"
            actions={
              <>
                <PrintButton />
                {mayEdit && !isTerminal && (
                  <button type="button" className="btn" onClick={() => setEditOpen(true)}>
                    <PencilLine size={16} /> Edit
                  </button>
                )}
                {mayEdit && canAdvance && (
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={transition.isPending}
                    onClick={() => runTransition('advance', 'Document advanced to next stage')}
                  >
                    <ArrowRight size={16} /> Advance
                  </button>
                )}
                {mayEdit && canApprove && (
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={transition.isPending}
                    onClick={() => runTransition('approve', 'Document approved')}
                  >
                    <CheckCircle2 size={16} /> Approve
                  </button>
                )}
                {mayEdit && canRevise && (
                  <button
                    type="button"
                    className="btn"
                    disabled={transition.isPending}
                    onClick={() => runTransition('revise', 'Document returned to Work In Progress')}
                  >
                    <RotateCcw size={16} /> Revise
                  </button>
                )}
                {mayEdit && !isTerminal && (
                  <button
                    type="button"
                    className="btn btn-danger"
                    disabled={transition.isPending}
                    onClick={() => runTransition('obsolete', 'Document marked obsolete')}
                  >
                    <Ban size={16} /> Mark Obsolete
                  </button>
                )}
              </>
            }
          />

          <div className="card" style={{ marginBottom: 16 }}>
            <div className="card__header"><div className="card__title">Approval Workflow</div></div>
            <div className="card__body">
              <StageStepper status={doc.status} />
            </div>
          </div>

          <div className="detail-grid">
            <div className="stack">
              {TEMPLATE_SECTIONS.map((section) => {
                const value = doc[section.key] as string | undefined;
                return (
                  <div className="card" key={section.key}>
                    <div className="card__header">
                      <div className="card__title">{section.label}</div>
                    </div>
                    <div className="card__body">
                      <p style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                        {value && value.trim() ? value : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                      </p>
                    </div>
                  </div>
                );
              })}
            </div>

            <div className="stack">
              <div className="card">
                <div className="card__header"><div className="card__title">Document</div></div>
                <div className="card__body">
                  <DataList
                    items={[
                      { label: 'Type', value: docTypeLabel(doc.doc_type) },
                      { label: 'Department', value: departmentLabel(doc.department) },
                      { label: 'Revision', value: doc.current_revision ?? '—' },
                      { label: 'Version', value: doc.version ?? '—' },
                      { label: 'Owner', value: <UserName id={doc.owner_id} /> },
                      { label: 'Approved By', value: <UserName id={doc.approved_by} /> },
                      { label: 'AS9100 Clause', value: doc.as9100_clause ?? '—' },
                      { label: 'Effective', value: formatDate(doc.effective_date) },
                      { label: 'Last Review', value: formatDate(doc.last_review_date) },
                      { label: 'Next Review', value: formatDate(doc.next_review_date) },
                    ]}
                  />
                </div>
              </div>
            </div>
          </div>

          <RecordSupplements entityType="document" entityId={doc.id} canEditPage="documents" />

          <DocumentFormModal
            open={editOpen}
            onClose={() => setEditOpen(false)}
            onSaved={() => setEditOpen(false)}
            document={doc}
          />
        </>
      )}
    </DetailState>
  );
}
