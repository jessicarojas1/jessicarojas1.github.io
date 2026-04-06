import { cn, statusLabel, statusColor } from '@/lib/utils';
import { ControlStatus } from '@/lib/types';

export function StatusBadge({ status, size = 'sm' }: { status: ControlStatus; size?: 'xs' | 'sm' }) {
  return (
    <span className={cn(
      'inline-flex items-center rounded-full border font-medium',
      statusColor(status),
      size === 'xs' ? 'text-xs px-2 py-0.5' : 'text-xs px-2.5 py-1'
    )}>
      {statusLabel(status)}
    </span>
  );
}
