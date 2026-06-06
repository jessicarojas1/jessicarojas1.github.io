import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { ShieldX } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { can, type Capability } from '@/lib/rbac';
import { usePagePerms } from '@/lib/permissions';
import { EmptyState } from './EmptyState';

export function ProtectedRoute({
  children,
  capability,
  page,
}: {
  children: ReactNode;
  capability?: Capability;
  /** Dynamic page key; gated via usePagePerms().canView (with static fallback). */
  page?: string;
}) {
  const { isAuthenticated, loading, user } = useAuth();
  const { canView } = usePagePerms();
  const location = useLocation();

  if (loading) {
    return (
      <div className="loading-block" style={{ minHeight: '60vh' }}>
        <span className="spinner spinner--lg" />
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  // Page-key gating takes precedence; canView already degrades to the static
  // capability fallback so a loading/errored /permissions/me never locks out.
  // The capability prop remains the fallback when no page key is supplied.
  const allowed = page ? canView(page) : capability ? can(user?.roles, capability) : true;

  if (!allowed) {
    return (
      <div style={{ minHeight: '50vh', display: 'grid', placeItems: 'center' }}>
        <EmptyState
          icon={ShieldX}
          title="Access restricted"
          description="Your role does not have permission to view this area. Contact a Quality Manager or Administrator if you believe this is an error."
        />
      </div>
    );
  }

  return <>{children}</>;
}
