import type { Metadata } from 'next';
import './globals.css';
import { AppShell } from '@/components/layout/AppShell';
import { BrandingProvider } from '@/components/branding/BrandingProvider';

export const metadata: Metadata = {
  title: 'Compliance Copilot — CMMC & NIST 800-171',
  description: 'Enterprise compliance readiness for CMMC Level 2/3 and NIST SP 800-171',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="dark">
      <body>
        <BrandingProvider>
          <AppShell>{children}</AppShell>
        </BrandingProvider>
      </body>
    </html>
  );
}
