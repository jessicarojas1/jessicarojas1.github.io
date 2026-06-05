import type { LucideIcon } from 'lucide-react';
import { TrendingDown, TrendingUp } from 'lucide-react';

type Tone = 'primary' | 'danger' | 'warning' | 'success';

export function KpiCard({
  icon: Icon,
  value,
  label,
  tone = 'primary',
  delta,
  deltaDirection,
}: {
  icon: LucideIcon;
  value: string | number;
  label: string;
  tone?: Tone;
  delta?: string;
  deltaDirection?: 'up' | 'down';
}) {
  const toneClass = tone === 'primary' ? '' : `is-${tone}`;
  return (
    <div className="kpi-card">
      <div className={`kpi-card__icon ${toneClass}`}>
        <Icon size={20} aria-hidden />
      </div>
      <div>
        <div className="kpi-card__value">{value}</div>
        <div className="kpi-card__label">{label}</div>
        {delta && (
          <div className={`kpi-card__delta ${deltaDirection ?? ''}`}>
            {deltaDirection === 'up' ? <TrendingUp size={13} /> : <TrendingDown size={13} />}
            {delta}
          </div>
        )}
      </div>
    </div>
  );
}
