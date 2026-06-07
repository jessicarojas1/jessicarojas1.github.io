/**
 * Pure helpers for the AEGIS-style 5×5 risk matrix.
 *
 * Sentinel stores severity & likelihood on a 1–10 scale; these map values onto
 * the 5×5 likelihood × impact grid and translate scores/RPN into risk levels.
 * Kept separate from the RiskHeatMap component so the component file stays
 * fast-refresh friendly (component-only export).
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
