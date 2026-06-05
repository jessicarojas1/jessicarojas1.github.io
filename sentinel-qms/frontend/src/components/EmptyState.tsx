import type { LucideIcon } from 'lucide-react';
import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
  icon: Icon = Inbox,
  title,
  description,
  action,
}: {
  icon?: LucideIcon;
  title: string;
  description?: string;
  action?: ReactNode;
}) {
  return (
    <div className="empty-state">
      <div className="empty-state__icon">
        <Icon size={24} aria-hidden />
      </div>
      <h3>{title}</h3>
      {description && <p className="muted text-sm" style={{ maxWidth: 360, margin: 0 }}>{description}</p>}
      {action}
    </div>
  );
}
