import { cn, priorityColor } from '@/lib/utils';
import { Priority } from '@/lib/types';

const DOTS: Record<Priority, string> = {
  critical: 'bg-red-400', high: 'bg-orange-400', medium: 'bg-amber-400', low: 'bg-slate-400'
};

export function PriorityBadge({ priority }: { priority: Priority }) {
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-xs font-medium rounded-full px-2.5 py-1', priorityColor(priority))}>
      <span className={cn('w-1.5 h-1.5 rounded-full', DOTS[priority])} />
      {priority.charAt(0).toUpperCase() + priority.slice(1)}
    </span>
  );
}
