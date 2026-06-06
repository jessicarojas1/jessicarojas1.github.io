import { Printer } from 'lucide-react';

/** Small reusable button that triggers the browser print dialog. */
export function PrintButton({ label = 'Print' }: { label?: string }) {
  return (
    <button
      type="button"
      className="btn btn-sm no-print"
      onClick={() => window.print()}
      title="Print this record"
    >
      <Printer size={14} /> {label}
    </button>
  );
}
