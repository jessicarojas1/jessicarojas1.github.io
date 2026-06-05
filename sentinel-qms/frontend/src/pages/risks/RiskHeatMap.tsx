import type { Risk } from '@/types';

function band(rpnLikeProduct: number): string {
  // product of severity * occurrence (1..100) -> tone
  if (rpnLikeProduct >= 64) return 'heat-crit';
  if (rpnLikeProduct >= 36) return 'heat-high';
  if (rpnLikeProduct >= 15) return 'heat-med';
  return 'heat-low';
}

/** Severity (rows) x Occurrence (cols) heat map; cell shows count of risks. */
export function RiskHeatMap({ risks }: { risks: Risk[] }) {
  // Build a 10x10 grid keyed by `${severity}-${occurrence}`.
  const counts = new Map<string, number>();
  for (const r of risks) {
    const key = `${r.severity}-${r.likelihood}`;
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  const rows = Array.from({ length: 10 }, (_, i) => 10 - i); // severity 10..1 top-down
  const cols = Array.from({ length: 10 }, (_, i) => i + 1); // likelihood 1..10

  return (
    <div>
      <div className="heatmap" role="img" aria-label="Risk heat map by severity and occurrence">
        <div className="heatmap__axis" />
        {cols.map((c) => (
          <div key={`h-${c}`} className="heatmap__axis">
            {c}
          </div>
        ))}
        {rows.map((sev) => (
          <div key={`row-${sev}`} style={{ display: 'contents' }}>
            <div className="heatmap__axis">{sev}</div>
            {cols.map((occ) => {
              const n = counts.get(`${sev}-${occ}`) ?? 0;
              return (
                <div
                  key={`${sev}-${occ}`}
                  className={`heatmap__cell ${band(sev * occ)}`}
                  style={{ opacity: n ? 1 : 0.18 }}
                  title={`Severity ${sev} × Likelihood ${occ}: ${n} risk(s)`}
                >
                  {n || ''}
                </div>
              );
            })}
          </div>
        ))}
      </div>
      <div className="row text-sm muted" style={{ marginTop: 10, justifyContent: 'space-between' }}>
        <span>← Likelihood (1–10) →</span>
        <span>Vertical: Severity (1–10)</span>
      </div>
    </div>
  );
}
