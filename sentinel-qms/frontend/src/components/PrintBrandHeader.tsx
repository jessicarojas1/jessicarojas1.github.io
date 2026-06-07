import { useBranding } from '@/hooks';
import { BrandIcon } from '@/lib/nav';

/**
 * Branded header shown ONLY in print / PDF output (hidden on screen via the
 * `print-brand-header` CSS rule). Carries the configured logo + organization
 * name onto every printed page and generated report.
 */
export function PrintBrandHeader() {
  const branding = useBranding();

  return (
    <div className="print-brand-header" aria-hidden>
      <span className="print-brand-header__mark">
        {branding.logoUrl ? (
          <img src={branding.logoUrl} alt="" className="print-brand-header__img" />
        ) : (
          <BrandIcon size={18} />
        )}
      </span>
      <span className="print-brand-header__name">{branding.name}</span>
    </div>
  );
}
