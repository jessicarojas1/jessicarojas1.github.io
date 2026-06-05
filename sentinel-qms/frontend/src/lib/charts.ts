/** Shared color palette for recharts components (theme-aware via CSS vars at render). */
export const CHART_COLORS = {
  primary: '#1d4e89',
  accent: '#0f766e',
  success: '#1a7f4b',
  warning: '#b45309',
  danger: '#b3261e',
  info: '#1f6fb2',
  grid: 'rgba(128,140,160,0.18)',
};

export const PIE_COLORS = ['#1a7f4b', '#b45309', '#1f6fb2', '#b3261e', '#6c7a90'];

/** Reusable tooltip style object for recharts <Tooltip contentStyle>. */
export const tooltipStyle = {
  background: 'var(--bg-elevated)',
  border: '1px solid var(--border)',
  borderRadius: 6,
  fontSize: 12,
  color: 'var(--text)',
};
