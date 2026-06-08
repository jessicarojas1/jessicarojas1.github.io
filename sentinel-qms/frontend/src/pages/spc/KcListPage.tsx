import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useCreateKc, useKeyCharacteristics, useUserLookup } from '@/hooks';
import { usePagePerms } from '@/lib/permissions';
import { useToast } from '@/lib/toast';
import { getErrorMessage } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/EmptyState';
import type { KcClass } from '@/types';

const CLASSES: KcClass[] = ['critical', 'major', 'minor'];

/** Cpk RAG: >=1.33 good, >=1.0 marginal, else poor. */
export function cpkTone(cpk: number | null): string {
  if (cpk == null) return 'medium';
  if (cpk >= 1.33) return 'low';
  if (cpk >= 1.0) return 'medium';
  return 'high';
}

export default function KcListPage() {
  const { data, isLoading, error } = useKeyCharacteristics();
  const create = useCreateKc();
  const { canEdit } = usePagePerms();
  const { notify } = useToast();
  const writable = canEdit('inspections');
  const [part, setPart] = useState('');
  const [characteristic, setCharacteristic] = useState('');
  const [usl, setUsl] = useState('');
  const [lsl, setLsl] = useState('');
  const [kcClass, setKcClass] = useState<KcClass>('major');
  const [ownerId, setOwnerId] = useState('');
  const { list: users } = useUserLookup();

  const add = (e: React.FormEvent) => {
    e.preventDefault();
    if (!part.trim() || !characteristic.trim()) return;
    create.mutate(
      {
        part_number: part.trim(),
        characteristic: characteristic.trim(),
        usl: usl.trim() === '' ? null : Number(usl),
        lsl: lsl.trim() === '' ? null : Number(lsl),
        kc_class: kcClass,
        owner_id: ownerId === '' ? null : Number(ownerId),
      },
      {
        onSuccess: () => {
          setPart('');
          setCharacteristic('');
          setUsl('');
          setLsl('');
          setOwnerId('');
          notify('Key characteristic added', 'success');
        },
        onError: (err) => notify(getErrorMessage(err), 'danger'),
      },
    );
  };

  return (
    <>
      <PageHeader
        title="Key Characteristics & SPC"
        subtitle="Controlled features with spec limits, variable data, and Cp/Cpk capability."
        breadcrumbs={[{ label: 'Operations' }, { label: 'Key Characteristics' }]}
      />
      {writable && (
        <form className="std-new" onSubmit={add}>
          <input className="input" placeholder="Part #" value={part} onChange={(e) => setPart(e.target.value)} aria-label="Part number" />
          <input className="input" placeholder="Characteristic" value={characteristic} onChange={(e) => setCharacteristic(e.target.value)} aria-label="Characteristic" />
          <input className="input" type="number" step="any" placeholder="LSL" value={lsl} onChange={(e) => setLsl(e.target.value)} aria-label="LSL" style={{ maxWidth: 90 }} />
          <input className="input" type="number" step="any" placeholder="USL" value={usl} onChange={(e) => setUsl(e.target.value)} aria-label="USL" style={{ maxWidth: 90 }} />
          <select className="input" value={kcClass} onChange={(e) => setKcClass(e.target.value as KcClass)} aria-label="Class">
            {CLASSES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
          <select className="input" value={ownerId} onChange={(e) => setOwnerId(e.target.value)} aria-label="Owner">
            <option value="">Owner (unassigned)</option>
            {users.filter((u) => u.is_active).map((u) => <option key={u.id} value={u.id}>{u.full_name || u.email}</option>)}
          </select>
          <button type="submit" className="btn btn-primary btn-sm" disabled={create.isPending}>Add KC</button>
        </form>
      )}

      {error ? (
        <div className="card"><div className="card__body"><EmptyState title="Unable to load" description={getErrorMessage(error)} /></div></div>
      ) : isLoading || !data ? (
        <div className="card"><div className="card__body"><span className="spinner" /> Loading…</div></div>
      ) : data.length === 0 ? (
        <div className="card"><div className="card__body"><EmptyState title="No key characteristics" description="Add a KC to start collecting SPC data." /></div></div>
      ) : (
        <div className="card">
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr><th>KC</th><th>Part</th><th>Characteristic</th><th>Class</th><th>Owner</th><th>Spec</th><th>n</th><th>Cpk</th></tr>
              </thead>
              <tbody>
                {data.map((kc) => (
                  <tr key={kc.id}>
                    <td className="mono"><Link to={`/key-characteristics/${kc.id}`} className="link-btn">{kc.kc_number}</Link></td>
                    <td className="mono">{kc.part_number}</td>
                    <td>{kc.characteristic}</td>
                    <td><span className={`cfp-risk cfp-risk--${kc.kc_class === 'critical' ? 'critical' : kc.kc_class === 'major' ? 'high' : 'medium'}`}>{kc.kc_class}</span></td>
                    <td>{kc.owner_name ?? '—'}</td>
                    <td className="mono">{kc.lsl ?? '—'} / {kc.usl ?? '—'}</td>
                    <td>{kc.capability.count}</td>
                    <td>
                      {kc.capability.cpk != null ? (
                        <span className={`cfp-risk cfp-risk--${cpkTone(kc.capability.cpk)}`}>{kc.capability.cpk}</span>
                      ) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  );
}
