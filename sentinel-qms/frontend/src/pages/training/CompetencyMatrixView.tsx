import type { CompetencyMatrix } from '@/types';

const LEVEL_LABELS = ['None', 'Trainee', 'Practitioner', 'Proficient', 'Expert'];

export function CompetencyMatrixView({ matrix }: { matrix?: CompetencyMatrix }) {
  if (!matrix || matrix.rows.length === 0) {
    return (
      <div className="card"><div className="card__body">
        <div className="empty-state-sm">No competency data available.</div>
      </div></div>
    );
  }

  return (
    <div className="card">
      <div className="card__header">
        <div className="card__title">Competency Matrix</div>
        <div className="row text-sm muted" style={{ gap: 12 }}>
          {LEVEL_LABELS.map((l, i) => (
            <span key={l} className="row" style={{ gap: 4 }}>
              <span className={`comp-level comp-${i}`} style={{ width: 18, height: 18 }}>{i}</span>
              {l}
            </span>
          ))}
        </div>
      </div>
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th style={{ position: 'sticky', left: 0 }}>Employee</th>
              <th>Department</th>
              {matrix.competencies.map((c) => (
                <th key={c} style={{ textAlign: 'center' }}>{c}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {matrix.rows.map((row) => {
              const byComp = new Map(row.cells.map((c) => [c.competency, c.level]));
              return (
                <tr key={row.employee_id}>
                  <td><strong>{row.employee_name}</strong></td>
                  <td className="muted text-sm">{row.department ?? '—'}</td>
                  {matrix.competencies.map((c) => {
                    const level = byComp.get(c) ?? 0;
                    return (
                      <td key={c} style={{ textAlign: 'center' }}>
                        <span
                          className={`comp-level comp-${level}`}
                          title={`${c}: ${LEVEL_LABELS[level]}`}
                        >
                          {level}
                        </span>
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
