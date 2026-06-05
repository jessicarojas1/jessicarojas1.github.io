import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { ShieldX } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { can, type Capability } from '@/lib/rbac';
import { EmptyState } from './EmptyState';

export function ProtectedRoute({
  children,
  capability,
}: {
  children: ReactNode;
  capability?: Capability;
}) {
  const { isAuthenticated, loading, user } = useAuth();
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

  if (capability && !can(user?.roles, capability)) {
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
