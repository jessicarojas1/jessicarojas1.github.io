import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  Bell,
  ChevronDown,
  LogOut,
  Menu,
  Search,
  ShieldCheck,
  UserCircle,
} from 'lucide-react';
import { useAuth } from '@/lib/auth';
import { formatDateTime, humanize, initials } from '@/lib/format';
import { ROLE_LABELS } from '@/types';
import {
  useBranding,
  useDebounced,
  useGlobalSearch,
  useMarkAllRead,
  useMarkRead,
  useNotifications,
  useUnreadCount,
} from '@/hooks';
import { ThemeToggle } from './ThemeToggle';
import { BrandIcon } from '@/lib/nav';

export function TopBar({ onToggleNav }: { onToggleNav: () => void }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const branding = useBranding();
  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  // Resilient, sanitized branding: falls back to defaults while loading / on error.
  const brandName = branding.name;
  const logoUrl = branding.logoUrl;

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const handleLogout = async () => {
    await logout();
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

      <Link to="/" className="topbar__brand" aria-label={`${brandName} — go to dashboard`}>
        <span className="logo-mark">
          {logoUrl ? (
            <img src={logoUrl} alt="" className="logo-mark__img" />
          ) : (
            <BrandIcon size={16} />
          )}
        </span>
        {brandName}
      </Link>

      <GlobalSearch />

      <div className="topbar__spacer" />

      <div className="topbar__actions">
        <NotificationBell />
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
              <Link to="/profile" className="dropdown__item" role="menuitem" onClick={() => setMenuOpen(false)}>
                <UserCircle size={16} />
                Profile &amp; preferences
              </Link>
              <div className="dropdown__item" role="menuitem">
                <ShieldCheck size={16} />
                {user?.roles.map((r) => ROLE_LABELS[r]).join(', ')}
              </div>
              <button type="button" className="dropdown__item" onClick={() => void handleLogout()} role="menuitem">
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

/* ------------------------------------------------------------------ */
/* Global search                                                       */
/* ------------------------------------------------------------------ */
function GlobalSearch() {
  const navigate = useNavigate();
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const debounced = useDebounced(term, 250);
  const { data: results = [], isFetching } = useGlobalSearch(debounced);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  const select = (url: string) => {
    setTerm('');
    setOpen(false);
    navigate(url);
  };

  const q = debounced.trim();
  const showDropdown = open && q.length >= 2;

  // Group results by humanized type for labeled sections.
  const groups = results.reduce<Record<string, typeof results>>((acc, r) => {
    (acc[r.type] ??= []).push(r);
    return acc;
  }, {});

  return (
    <div className="topbar__search" ref={ref}>
      <Search size={15} />
      <input
        type="search"
        placeholder="Search NCRs, CAPAs, documents…"
        aria-label="Global search"
        value={term}
        onChange={(e) => {
          setTerm(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
      />
      {showDropdown && (
        <div className="search-results" role="listbox">
          {isFetching && results.length === 0 ? (
            <div className="search-results__empty">
              <span className="spinner" /> Searching…
            </div>
          ) : results.length === 0 ? (
            <div className="search-results__empty">No results for “{q}”.</div>
          ) : (
            Object.entries(groups).map(([type, items]) => (
              <div key={type} className="search-results__group">
                <div className="search-results__label">{humanize(type)}</div>
                {items.map((r) => (
                  <button
                    key={`${r.type}-${r.id}`}
                    type="button"
                    className="search-results__item"
                    role="option"
                    onClick={() => select(r.url)}
                  >
                    <span className="mono search-results__num">{r.number}</span>
                    <span className="search-results__title">{r.title}</span>
                  </button>
                ))}
              </div>
            ))
          )}
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Notification bell                                                   */
/* ------------------------------------------------------------------ */
function NotificationBell() {
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { data: count = 0 } = useUnreadCount();
  const { data, isLoading } = useNotifications({ page: 1, size: 10 });
  const markRead = useMarkRead();
  const markAllRead = useMarkAllRead();

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const items = data?.items ?? [];

  const handleClick = async (id: number, url?: string | null, isRead?: boolean) => {
    if (!isRead) {
      try {
        await markRead.mutateAsync(id);
      } catch {
        /* non-fatal */
      }
    }
    setOpen(false);
    if (url) navigate(url);
  };

  return (
    <div className="notif" ref={ref}>
      <button
        type="button"
        className="btn btn-icon btn-ghost notif__btn"
        onClick={() => setOpen((o) => !o)}
        aria-label={`Notifications${count ? ` (${count} unread)` : ''}`}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <Bell size={18} />
        {count > 0 && <span className="notif__badge">{count > 99 ? '99+' : count}</span>}
      </button>

      {open && (
        <div className="dropdown notif__panel" role="menu">
          <div className="notif__header">
            <strong>Notifications</strong>
            {count > 0 && (
              <button
                type="button"
                className="btn btn-sm btn-ghost"
                onClick={() => markAllRead.mutate()}
                disabled={markAllRead.isPending}
              >
                Mark all read
              </button>
            )}
          </div>
          <div className="notif__list">
            {isLoading ? (
              <div className="search-results__empty">
                <span className="spinner" /> Loading…
              </div>
            ) : items.length === 0 ? (
              <div className="search-results__empty">You’re all caught up.</div>
            ) : (
              items.map((n) => (
                <button
                  key={n.id}
                  type="button"
                  className={`notif__item ${n.is_read ? '' : 'notif__item--unread'}`}
                  onClick={() => handleClick(n.id, n.url, n.is_read)}
                >
                  <span className="notif__msg">{n.message ?? n.title ?? 'Notification'}</span>
                  <span className="notif__time">{formatDateTime(n.created_at)}</span>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
