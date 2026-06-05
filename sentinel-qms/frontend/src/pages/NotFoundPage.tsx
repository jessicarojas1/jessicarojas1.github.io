import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';
import { EmptyState } from '@/components/EmptyState';

export default function NotFoundPage() {
  return (
    <div style={{ minHeight: '60vh', display: 'grid', placeItems: 'center' }}>
      <EmptyState
        icon={Compass}
        title="Page not found"
        description="The page you requested does not exist or has moved."
        action={
          <Link to="/" className="btn btn-primary">
            Return to Dashboard
          </Link>
        }
      />
    </div>
  );
}
