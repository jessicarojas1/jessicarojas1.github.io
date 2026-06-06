import { useRef, useState } from 'react';
import type { QueryKey } from '@tanstack/react-query';
import { AlertCircle, CheckCircle2, Download, Upload } from 'lucide-react';
import { getErrorMessage } from '@/lib/api';
import { useToast } from '@/lib/toast';
import {
  useBulkImport,
  useImportTemplateUrl,
  type ImportResult,
} from '@/hooks/useImport';
import { Modal } from '@/components/Modal';

export function ImportModal({
  resource,
  title,
  open,
  onClose,
  listQueryKey,
  onImported,
}: {
  resource: string;
  title: string;
  open: boolean;
  onClose: () => void;
  listQueryKey: QueryKey;
  onImported?: (result: ImportResult) => void;
}) {
  const { notify } = useToast();
  const downloadTemplate = useImportTemplateUrl(resource);
  const importer = useBulkImport(resource, listQueryKey);
  const fileRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);

  const reset = () => {
    setFile(null);
    setResult(null);
    if (fileRef.current) fileRef.current.value = '';
  };

  const handleClose = () => {
    reset();
    onClose();
  };

  const handleDownload = async () => {
    try {
      await downloadTemplate();
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  const handleImport = async () => {
    if (!file) return;
    try {
      const res = await importer.mutateAsync(file);
      setResult(res);
      const kind = res.failed > 0 ? (res.created > 0 ? 'info' : 'danger') : 'success';
      notify(`Imported ${res.created} record(s), ${res.failed} failed`, kind);
      onImported?.(res);
    } catch (err) {
      notify(getErrorMessage(err), 'danger');
    }
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={title}
      size="lg"
      footer={
        <>
          <button type="button" className="btn" onClick={handleClose} disabled={importer.isPending}>
            Close
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={handleImport}
            disabled={!file || importer.isPending}
          >
            {importer.isPending ? <span className="spinner" /> : (<><Upload size={16} /> Import</>)}
          </button>
        </>
      }
    >
      <p className="muted" style={{ marginTop: 0 }}>
        Download the CSV template, fill in one record per row, then upload it to bulk-create
        records. Rows that fail validation are skipped and listed below.
      </p>

      <div style={{ marginBottom: 16 }}>
        <button type="button" className="btn btn-sm" onClick={handleDownload}>
          <Download size={16} /> Download template
        </button>
      </div>

      <div className="form-field">
        <label htmlFor="import-file">CSV file</label>
        <input
          id="import-file"
          className="input"
          ref={fileRef}
          type="file"
          accept=".csv,text/csv"
          onChange={(e) => {
            setFile(e.target.files?.[0] ?? null);
            setResult(null);
          }}
        />
      </div>

      {result && (
        <div className="import-result">
          <div className="import-result__summary">
            <span className="import-result__stat import-result__stat--ok">
              <CheckCircle2 size={16} /> {result.created} created
            </span>
            <span className="import-result__stat import-result__stat--fail">
              <AlertCircle size={16} /> {result.failed} failed
            </span>
          </div>
          {result.errors.length > 0 && (
            <ul className="import-result__errors">
              {result.errors.map((e) => (
                <li key={e.row} className="import-result__error">
                  <span className="import-result__row">Row {e.row}</span>
                  <span className="import-result__message">{e.message}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Modal>
  );
}
