import type { ReactNode } from 'react';
import { Breadcrumbs, type Crumb } from './Breadcrumbs';

export function PageHeader({
  title,
  subtitle,
  breadcrumbs,
  actions,
  icon,
}: {
  title: ReactNode;
  subtitle?: ReactNode;
  breadcrumbs?: Crumb[];
  actions?: ReactNode;
  icon?: ReactNode;
}) {
  return (
    <>
      {breadcrumbs && <Breadcrumbs items={breadcrumbs} />}
      <div className="page-header">
        <div className="page-header__titles">
          <h1 className="page-title">
            {icon}
            {title}
          </h1>
          {subtitle && <div className="page-subtitle">{subtitle}</div>}
        </div>
        {actions && <div className="page-header__actions">{actions}</div>}
      </div>
    </>
  );
}
