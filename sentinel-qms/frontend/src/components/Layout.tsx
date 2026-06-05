import { useState } from 'react';
import { Outlet } from 'react-router-dom';
import { CuiBanner } from './CuiBanner';
import { TopBar } from './TopBar';
import { SideNav } from './SideNav';

export function Layout() {
  const [navOpen, setNavOpen] = useState(false);

  return (
    <div className="app-shell">
      <CuiBanner position="top" />
      <TopBar onToggleNav={() => setNavOpen((o) => !o)} />
      <SideNav open={navOpen} onNavigate={() => setNavOpen(false)} />
      {navOpen && (
        <div
          className="nav-backdrop"
          onClick={() => setNavOpen(false)}
          role="presentation"
        />
      )}
      <main className="content">
        <div className="content__inner">
          <Outlet />
        </div>
      </main>
      <CuiBanner position="bottom" />
    </div>
  );
}
