import { useState } from 'react';
import { FileDown } from 'lucide-react';
import { api, getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';

interface PdfButtonProps {
  /** API path (relative to the v1 base) that returns a PDF, e.g. /reports/ncr/1/pdf. */
  path: string;
  /** Suggested download filename. */
  filename: string;
  label?: string;
  className?: string;
}

/**
 * Downloads a branded PDF from an authenticated endpoint. Fetches via the API
 * client (so the bearer token is attached), then triggers a browser download.
 */
export function PdfButton({ path, filename, label = 'PDF', className = 'btn btn-sm no-print' }: PdfButtonProps) {
  const [busy, setBusy] = useState(false);
  const { notify } = useToast();

  const download = async () => {
    setBusy(true);
    try {
      const { data } = await api.get<Blob>(path, { responseType: 'blob' });
      const url = URL.createObjectURL(data);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      type="button"
      className={className}
      onClick={download}
      disabled={busy}
      title="Download as branded PDF"
    >
      {busy ? <span className="spinner" /> : <FileDown size={14} />} {label}
    </button>
  );
}
