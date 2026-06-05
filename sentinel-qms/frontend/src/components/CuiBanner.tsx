/**
 * Controlled Unclassified Information banner. DoD systems must mark CUI
 * handling at the top and bottom of every screen.
 */
export function CuiBanner({ position }: { position: 'top' | 'bottom' }) {
  const label = import.meta.env.VITE_DEPLOYMENT_LABEL;
  return (
    <div className={`cui-banner cui-banner--${position}`} role="note">
      CUI // Controlled Unclassified Information
      {position === 'bottom' && label && <small>· {label}</small>}
    </div>
  );
}
