import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';
import { useCreateStandard, useStandards } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import type { CoverageSummary } from '@/types';

function covTone(pct: number): string {
  if (pct >= 90) return 'good';
  if (pct >= 60) return 'warn';
  return 'bad';
}

export function CoverageBar({ coverage }: { coverage: CoverageSummary }) {
  return (
    <div className="cov">
      <div className="cov-bar" role="img" aria-label={`${coverage.coverage_pct}% covered`}>
        <span className={`cov-bar__fill cov-${covTone(coverage.coverage_pct)}`} style={{ width: `${coverage.coverage_pct}%` }} />
      </div>
      <div className="cov-meta">
        <strong>{coverage.coverage_pct}%</strong>
        <span className="cov-counts">
          {coverage.covered} covered · {coverage.partial} partial · {coverage.gap} gap
          {coverage.not_applicable ? ` · ${coverage.not_applicable} N/A` : ''}
        </span>
      </div>
    </div>
  );
}

export default function StandardsPage() {
  const { data, isLoading, error } = useStandards();
  const create = useCreateStandard();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('users');
  const [code, setCode] = useState('');
  const [name, setName] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!code.trim() || !name.trim()) return;
    create.mutate(
      { code: code.trim(), name: name.trim() },
      {
        onSuccess: () => {
          notify('Standard added', 'success');
          setCode('');
          setName('');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Standards & Coverage"
        subtitle="Map clauses to the QMS modules that satisfy them and track audit readiness per framework."
        breadcrumbs={[{ label: 'Administration' }, { label: 'Standards' }]}
      />

      {writable && (
        <form className="std-new" onSubmit={submit}>
          <input
            className="input"
            placeholder="Code (e.g. AS9110)"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            aria-label="Standard code"
          />
          <input
            className="input"
            placeholder="Name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            aria-label="Standard name"
          />
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>
            Add standard
          </button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load standards" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : data.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No standards yet" description="Add a framework to start mapping coverage." /></div></div>
      ) : (
        <div className="std-grid">
          {data.map((s) => (
            <Link key={s.id} to={`/standards/${s.id}`} className="std-card card">
              <div className="std-card__head">
                <ShieldCheck size={18} />
                <span className="std-card__code">{s.code}</span>
              </div>
              <div className="std-card__name">{s.name}</div>
              <CoverageBar coverage={s.coverage} />
            </Link>
          ))}
        </div>
      )}
    </>
  );
}
