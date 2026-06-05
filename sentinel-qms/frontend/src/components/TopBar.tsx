import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronDown, LogOut, Menu, Search, ShieldCheck, UserCircle } from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { initials } from '@/lib/format';
import { ROLE_LABELS } from '@/types';
import { ThemeToggle } from './ThemeToggle';
import { BrandIcon } from '@/lib/nav';

export function TopBar({ onToggleNav }: { onToggleNav: () => void }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const handleLogout = () => {
    logout();
    navigate('/login', { replace: true });
  };

  const primaryRole = user?.roles[0];

  return (
    <header className="topbar">
      <button
        type="button"
        className="btn btn-icon btn-ghost nav-toggle"
        onClick={onToggleNav}
        aria-label="Toggle navigation"
      >
        <Menu size={18} />
      </button>

      <div className="topbar__brand">
        <span className="logo-mark">
          <BrandIcon size={16} />
        </span>
        Sentinel QMS
      </div>

      <div className="topbar__search">
        <Search size={15} />
        <input
          type="search"
          placeholder="Search NCRs, CAPAs, documents…"
          aria-label="Global search"
        />
      </div>

      <div className="topbar__spacer" />

      <div className="topbar__actions">
        <ThemeToggle />

        <div className="user-menu" ref={menuRef}>
          <button
            type="button"
            className="user-menu__btn"
            onClick={() => setMenuOpen((o) => !o)}
            aria-haspopup="menu"
            aria-expanded={menuOpen}
          >
            <span className="avatar">{initials(user?.full_name)}</span>
            <span className="user-menu__meta">
              <strong>{user?.full_name}</strong>
              <span>{primaryRole ? ROLE_LABELS[primaryRole] : ''}</span>
            </span>
            <ChevronDown size={15} />
          </button>

          {menuOpen && (
            <div className="dropdown" role="menu">
              <div className="dropdown__header">
                <strong>{user?.full_name}</strong>
                <span>{user?.email}</span>
              </div>
              <div className="dropdown__item" role="menuitem">
                <UserCircle size={16} />
                {user?.title ?? 'Profile'}
              </div>
              <div className="dropdown__item" role="menuitem">
                <ShieldCheck size={16} />
                {user?.roles.map((r) => ROLE_LABELS[r]).join(', ')}
              </div>
              <button type="button" className="dropdown__item" onClick={handleLogout} role="menuitem">
                <LogOut size={16} />
                Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
