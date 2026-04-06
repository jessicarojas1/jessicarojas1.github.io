'use client';

import { useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard, ShieldCheck, FileText, BarChart3,
  Menu, X, ChevronRight, Bell, Settings, Shield
} from 'lucide-react';
import { cn } from '@/lib/utils';

const NAV = [
  { href: '/',          label: 'Dashboard',  icon: LayoutDashboard },
  { href: '/controls',  label: 'Controls',   icon: ShieldCheck },
  { href: '/evidence',  label: 'Evidence',   icon: FileText },
  { href: '/reports',   label: 'Reports',    icon: BarChart3 },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  const path = usePathname();

  return (
    <div className="flex h-screen overflow-hidden">
      {/* Sidebar */}
      <aside className={cn(
        'fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 border-r border-slate-800 flex flex-col transition-transform duration-200',
        open ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
      )}>
        {/* Logo */}
        <div className="flex items-center gap-3 px-5 py-5 border-b border-slate-800">
          <div className="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
            <Shield className="w-4 h-4 text-white" />
          </div>
          <div>
            <div className="text-sm font-bold text-slate-100">Compliance Copilot</div>
            <div className="text-xs text-slate-500">CMMC / NIST 800-171</div>
          </div>
          <button className="ml-auto md:hidden text-slate-400" onClick={() => setOpen(false)}>
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 py-4 px-3 space-y-1">
          {NAV.map(({ href, label, icon: Icon }) => {
            const active = href === '/' ? path === '/' : path.startsWith(href);
            return (
              <Link key={href} href={href}
                onClick={() => setOpen(false)}
                className={cn(
                  'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors group',
                  active
                    ? 'bg-blue-600/15 text-blue-400'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
                )}>
                <Icon className={cn('w-4 h-4 flex-shrink-0', active ? 'text-blue-400' : 'text-slate-500 group-hover:text-slate-300')} />
                {label}
                {active && <ChevronRight className="w-3 h-3 ml-auto text-blue-400" />}
              </Link>
            );
          })}
        </nav>

        {/* Footer */}
        <div className="px-4 py-4 border-t border-slate-800">
          <div className="flex items-center gap-2 px-2">
            <div className="w-7 h-7 rounded-full bg-slate-700 flex items-center justify-center text-xs text-slate-300 font-medium">SO</div>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium text-slate-300 truncate">Security Officer</div>
              <div className="text-xs text-slate-500">admin</div>
            </div>
            <Settings className="w-4 h-4 text-slate-500 hover:text-slate-300 cursor-pointer" />
          </div>
        </div>
      </aside>

      {/* Overlay */}
      {open && <div className="fixed inset-0 z-40 bg-black/60 md:hidden" onClick={() => setOpen(false)} />}

      {/* Main */}
      <div className="flex-1 md:ml-64 flex flex-col overflow-hidden">
        {/* Top bar */}
        <header className="h-14 border-b border-slate-800 bg-slate-900/80 backdrop-blur flex items-center px-4 gap-3 flex-shrink-0">
          <button className="md:hidden text-slate-400 hover:text-slate-200 p-1" onClick={() => setOpen(true)}>
            <Menu className="w-5 h-5" />
          </button>
          <span className="text-sm text-slate-500 hidden md:block">
            {NAV.find(n => (n.href === '/' ? path === '/' : path.startsWith(n.href)))?.label ?? 'Compliance Copilot'}
          </span>
          <div className="ml-auto flex items-center gap-2">
            <button className="btn-ghost p-2"><Bell className="w-4 h-4" /></button>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
