import type { Risk } from '@/types';

/**
 * AEGIS-style 5×5 risk matrix (heat map).
 *
 * Sentinel stores severity & likelihood on a 1–10 scale. To reproduce the AEGIS
 * 5×5 likelihood × impact grid we bucket each 1–10 value into 5 bands of two
 * (1–2 → band 1 … 9–10 → band 5). Each cell is coloured by its risk score
 * (band row × band col, 1–25) using AEGIS green→yellow→orange→red bands and
 * shows the count of risks that fall inside it.
 */

export type MatrixLevel = 'low' | 'medium' | 'high' | 'critical';

/** 1–10 scale value → 1–5 band. */
export function toBand(value: number): number {
  return Math.min(5, Math.max(1, Math.ceil(value / 2)));
}

/** Cell score (1–25) → AEGIS risk level. */
export function bandLevel(score: number): MatrixLevel {
  if (score > 14) return 'critical';
  if (score > 9) return 'high';
  if (score > 4) return 'medium';
  return 'low';
}

/** RPN (1–1000) → AEGIS-style level, used by summary cards / table. */
export function rpnLevel(rpn: number): MatrixLevel {
  if (rpn >= 200) return 'critical';
  if (rpn >= 100) return 'high';
  if (rpn >= 40) return 'medium';
  return 'low';
}

const LEVEL_VAR: Record<MatrixLevel, string> = {
  low: 'var(--success)',
  medium: 'var(--warning)',
  high: '#d97706',
  critical: 'var(--danger)',
};

const IMPACT_LABELS = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Severe'];
const LIKELIHOOD_LABELS = ['Rare', 'Unlikely', 'Possible', 'Likely', 'Almost Certain'];

export function RiskHeatMap({
  risks,
  activeCell,
  onCellSelect,
}: {
  risks: Risk[];
  activeCell?: string | null;
  onCellSelect?: (key: string | null) => void;
}) {
  // Count risks per (likelihoodBand, impactBand) cell.
  const counts = new Map<string, number>();
  for (const r of risks) {
    const key = `${toBand(r.likelihood)}-${toBand(r.severity)}`;
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }

  const rows = [5, 4, 3, 2, 1]; // likelihood high → low (top to bottom)
  const cols = [1, 2, 3, 4, 5]; // impact low → high (left to right)

  return (
    <div className="risk-matrix-wrap">
      <div className="risk-matrix">
        {/* Header row */}
        <div className="risk-matrix__corner">
          <span>Likelihood ↓ / Impact →</span>
        </div>
        {cols.map((c) => (
          <div key={`col-${c}`} className="risk-matrix__colhead">
            {IMPACT_LABELS[c - 1]}
            <span className="risk-matrix__idx">[{c}]</span>
          </div>
        ))}

        {/* Body rows */}
        {rows.map((rRow) => (
          <div key={`row-${rRow}`} style={{ display: 'contents' }}>
            <div className="risk-matrix__rowhead">
              {LIKELIHOOD_LABELS[rRow - 1]}
              <span className="risk-matrix__idx">[{rRow}]</span>
            </div>
            {cols.map((cCol) => {
              const score = rRow * cCol;
              const level = bandLevel(score);
              const color = LEVEL_VAR[level];
              const key = `${rRow}-${cCol}`;
              const n = counts.get(key) ?? 0;
              const isActive = activeCell === key;
              return (
                <button
                  type="button"
                  key={key}
                  className={`risk-matrix__cell ${isActive ? 'is-active' : ''}`}
                  style={{
                    background: `color-mix(in srgb, ${color} 20%, transparent)`,
                    borderColor: `color-mix(in srgb, ${color} 45%, transparent)`,
                  }}
                  title={`Likelihood ${rRow} × Impact ${cCol} = score ${score} (${level}) · ${n} risk(s)`}
                  onClick={() => onCellSelect?.(isActive ? null : key)}
                  disabled={!onCellSelect}
                >
                  <span className="risk-matrix__score" style={{ color }}>
                    {n || ''}
                  </span>
                </button>
              );
            })}
          </div>
        ))}
      </div>

      {/* Legend */}
      <div className="risk-matrix__legend">
        {(['low', 'medium', 'high', 'critical'] as MatrixLevel[]).map((lvl) => (
          <span key={lvl} className="risk-matrix__legend-item">
            <span className="risk-matrix__swatch" style={{ background: LEVEL_VAR[lvl] }} />
            <strong>{lvl[0].toUpperCase() + lvl.slice(1)}</strong>
          </span>
        ))}
        <span className="risk-matrix__legend-note">{risks.length} risks plotted</span>
      </div>
    </div>
  );
}
