import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarRange } from 'lucide-react';
import { useAuditPrograms, useCreateProgram } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';

export function ProgressBar({ pct }: { pct: number }) {
  const tone = pct >= 90 ? 'good' : pct >= 40 ? 'warn' : 'bad';
  return (
    <div className="cov">
      <div className="cov-bar">
        <span className={`cov-bar__fill cov-${tone}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="cov-meta">
        <strong>{pct}%</strong>
        <span className="cov-counts">complete</span>
      </div>
    </div>
  );
}

export default function AuditProgramsPage() {
  const { data, isLoading, error } = useAuditPrograms();
  const create = useCreateProgram();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('audits');
  const [name, setName] = useState('');
  const [year, setYear] = useState(String(new Date().getFullYear()));

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return;
    create.mutate(
      { name: name.trim(), year: Number(year) },
      {
        onSuccess: () => {
          setName('');
          notify('Audit program created', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Audit Program"
        subtitle="Plan and track the annual internal-audit schedule across the certification cycle."
        breadcrumbs={[{ label: 'Quality' }, { label: 'Audit Program' }]}
      />
      {writable && (
        <form className="std-new" onSubmit={submit}>
          <input className="input" placeholder="Program name" value={name} onChange={(e) => setName(e.target.value)} aria-label="Program name" />
          <input className="input" type="number" placeholder="Year" value={year} onChange={(e) => setYear(e.target.value)} aria-label="Year" style={{ maxWidth: 110 }} />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>New program</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : data.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No audit programs" description="Create a program to schedule audits." /></div></div>
      ) : (
        <div className="std-grid">
          {data.map((p) => (
            <Link key={p.id} to={`/audit-programs/${p.id}`} className="std-card card">
              <div className="std-card__head">
                <CalendarRange size={18} />
                <span className="std-card__code">{p.year}</span>
                <span className={`con-status con-status--${p.status === 'active' ? 'approved' : p.status === 'closed' ? 'closed' : 'draft'}`} style={{ marginLeft: 'auto' }}>{p.status}</span>
              </div>
              <div className="std-card__name">{p.name}</div>
              <ProgressBar pct={p.progress.completed_pct} />
              <div className="cov-counts" style={{ marginTop: 4 }}>{p.progress.completed}/{p.progress.total} audits complete</div>
            </Link>
          ))}
        </div>
      )}
    </>
  );
}
