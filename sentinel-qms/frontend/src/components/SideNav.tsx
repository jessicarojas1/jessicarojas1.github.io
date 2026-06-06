import { NavLink } from 'react-router-dom';
import { NAV_GROUPS } from '@/lib/nav';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { usePagePerms } from '@/lib/permissions';

export function SideNav({ open, onNavigate }: { open: boolean; onNavigate: () => void }) {
  const { user } = useAuth();
  const { canView } = usePagePerms();

  const groups = NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) =>
      // Prefer the dynamic page-permission check; fall back to the static
      // capability when an item has no page key (lockout-safe).
      item.page ? canView(item.page) : can(user?.roles, item.capability),
    ),
  })).filter((group) => group.items.length > 0);

  return (
    <nav className={`sidenav ${open ? 'open' : ''}`} aria-label="Primary">
      {groups.map((group) => (
        <div key={group.label}>
          <div className="sidenav__group-label">{group.label}</div>
          {group.items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}
              onClick={onNavigate}
            >
              <item.icon size={17} />
              <span>{item.label}</span>
            </NavLink>
          ))}
        </div>
      ))}
      <div className="sidenav__footer">
        Sentinel QMS v1.0
        <br />
        AS9100D · ISO 9001 · CMMC
      </div>
    </nav>
  );
}
