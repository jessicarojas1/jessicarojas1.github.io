'use client';

import { useEffect, useState } from 'react';
import { Shield } from 'lucide-react';
import { useBranding } from './BrandingProvider';
import { cn } from '@/lib/utils';

interface BrandMarkProps {
  /** Pixel size of the square mark. */
  size?: number;
  className?: string;
}

/**
 * Renders the organization logo when a valid logo URL is set, otherwise the
 * built-in Shield mark on the accent color. A broken logo image degrades
 * gracefully back to the built-in mark.
 */
export function BrandMark({ size = 32, className }: BrandMarkProps) {
  const { branding } = useBranding();
  const [broken, setBroken] = useState(false);

  // Reset the broken flag whenever the logo URL changes.
  useEffect(() => { setBroken(false); }, [branding.logoUrl]);

  const showLogo = branding.logoUrl !== '' && !broken;

  if (showLogo) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={branding.logoUrl}
        alt={`${branding.displayName} logo`}
        width={size}
        height={size}
        onError={() => setBroken(true)}
        className={cn('rounded-lg object-contain flex-shrink-0 bg-white/5', className)}
        style={{ width: size, height: size }}
      />
    );
  }

  return (
    <div
      className={cn('rounded-lg flex items-center justify-center flex-shrink-0', className)}
      style={{ width: size, height: size, background: 'var(--brand-accent)' }}
    >
      <Shield className="text-white" style={{ width: size * 0.5, height: size * 0.5 }} />
    </div>
  );
}
