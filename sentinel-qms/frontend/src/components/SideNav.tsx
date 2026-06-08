import { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { ChevronDown } from 'lucide-react';
import { NAV_GROUPS, type NavItem } from '@/lib/nav';
import { useAuth } from '@/lib/auth';
import { can } from '@/lib/rbac';
import { usePagePerms } from '@/lib/permissions';

/** True when the current path is (or is under) a nav item's route. */
function itemIsActive(item: NavItem, pathname: string): boolean {
  if (item.to === '/') return pathname === '/';
  return pathname === item.to || pathname.startsWith(`${item.to}/`);
}

export function SideNav({ open, onNavigate }: { open: boolean; onNavigate: () => void }) {
  const { user } = useAuth();
  const { canView } = usePagePerms();
  const { pathname } = useLocation();

  const groups = NAV_GROUPS.map((group) => ({
    ...group,
    items: group.items.filter((item) =>
      // Prefer the dynamic page-permission check; fall back to the static
      // capability when an item has no page key (lockout-safe).
      item.page
        ? canView(item.page)
        : item.capability
          ? can(user?.roles, item.capability)
          : true,
    ),
  })).filter((group) => group.items.length > 0);

  // The group whose route is currently active — always kept expanded so the
  // current page stays visible.
  const activeGroup = groups.find((g) => g.items.some((i) => itemIsActive(i, pathname)))?.label;

  const [openGroups, setOpenGroups] = useState<Set<string>>(
    () => new Set(activeGroup ? [activeGroup] : groups.map((g) => g.label)),
  );

  // Keep the active group expanded when navigation changes (e.g. via global
  // search into a collapsed section). Never auto-collapses what the user opened.
  useEffect(() => {
    if (activeGroup) {
      setOpenGroups((prev) => (prev.has(activeGroup) ? prev : new Set(prev).add(activeGroup)));
    }
  }, [activeGroup]);

  const toggle = (label: string) =>
    setOpenGroups((prev) => {
      const next = new Set(prev);
      if (next.has(label)) next.delete(label);
      else next.add(label);
      return next;
    });

  return (
    <nav className={`sidenav ${open ? 'open' : ''}`} aria-label="Primary">
      {groups.map((group) => {
        const isOpen = openGroups.has(group.label);
        const panelId = `nav-group-${group.label.replace(/\s+/g, '-').toLowerCase()}`;
        return (
          <div key={group.label} className="sidenav__group">
            <button
              type="button"
              className="sidenav__group-header"
              aria-expanded={isOpen}
              aria-controls={panelId}
              onClick={() => toggle(group.label)}
            >
              <span>{group.label}</span>
              <ChevronDown size={14} className={`sidenav__chevron ${isOpen ? 'open' : ''}`} />
            </button>
            {isOpen && (
              <div id={panelId} className="sidenav__group-items">
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
            )}
          </div>
        );
      })}
      <div className="sidenav__footer">
        Sentinel QMS v1.0
        <br />
        AS9100D · ISO 9001 · CMMC
      </div>
    </nav>
  );
}
