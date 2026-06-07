import { AppRouter } from './router';
import { useApplyBranding } from './hooks';

/** Applies live branding (document title + accent CSS var) for the whole app. */
function BrandingEffects() {
  useApplyBranding();
  return null;
}

export default function App() {
  return (
    <>
      <BrandingEffects />
      <AppRouter />
    </>
  );
}
