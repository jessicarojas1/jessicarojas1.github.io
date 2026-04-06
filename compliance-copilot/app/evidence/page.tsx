'use client';

import { useState, useCallback, useMemo } from 'react';
import { useDropzone } from 'react-dropzone';
import { Upload, Search, FileText, Tag, CheckCircle, Clock, Trash2, ExternalLink } from 'lucide-react';
import { SEED_EVIDENCE, SEED_CONTROLS } from '@/lib/data';
import { Evidence, EvidenceType } from '@/lib/types';
import { formatDate, formatBytes } from '@/lib/utils';

const TYPE_ICONS: Record<EvidenceType, string> = {
  policy:'📄', procedure:'📋', screenshot:'🖼️', log:'📝',
  configuration:'⚙️', test_result:'🔬', interview:'🎤', other:'📁'
};

export default function EvidencePage() {
  const [items, setItems]   = useState<Evidence[]>(SEED_EVIDENCE);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [uploading, setUploading]   = useState(false);

  const onDrop = useCallback((files: File[]) => {
    setUploading(true);
    setTimeout(() => {
      const newItems: Evidence[] = files.map(f => ({
        id: Math.random().toString(36).slice(2),
        control_ids: [],
        title: f.name.replace(/\.[^.]+$/, ''),
        description: null,
        type: 'other' as EvidenceType,
        file_url: null,
        file_name: f.name,
        file_size: f.size,
        tags: [],
        uploaded_by: 'You',
        reviewed: false,
        expiry_date: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      }));
      setItems(prev => [...newItems, ...prev]);
      setUploading(false);
    }, 1200);
  }, []);

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop,
    accept: {
      'application/pdf': ['.pdf'],
      'image/*': ['.png','.jpg','.jpeg','.gif','.webp'],
      'application/zip': ['.zip'],
      'text/*': ['.txt','.csv','.log'],
    }
  });

  const filtered = useMemo(() => items.filter(e => {
    if (typeFilter && e.type !== typeFilter) return false;
    if (search) {
      const q = search.toLowerCase();
      return e.title.toLowerCase().includes(q) ||
        e.tags.some(t => t.toLowerCase().includes(q)) ||
        (e.description ?? '').toLowerCase().includes(q);
    }
    return true;
  }), [items, search, typeFilter]);

  const controlName = (id: string) => SEED_CONTROLS.find(c => c.id === id)?.control_id ?? id;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-bold text-slate-100">Evidence Repository</h1>
        <p className="text-sm text-slate-400 mt-1">{items.length} items · {items.filter(e => e.reviewed).length} reviewed</p>
      </div>

      {/* Upload zone */}
      <div {...getRootProps()} className={`card p-8 border-2 border-dashed cursor-pointer transition-colors text-center
        ${isDragActive ? 'border-blue-500 bg-blue-500/5' : 'border-slate-700 hover:border-slate-600'}`}>
        <input {...getInputProps()} />
        {uploading
          ? <p className="text-sm text-slate-400">Uploading…</p>
          : <>
              <Upload className="w-8 h-8 text-slate-500 mx-auto mb-3" />
              <p className="text-sm font-medium text-slate-200">Drop evidence files here, or click to browse</p>
              <p className="text-xs text-slate-500 mt-1">PDF, images, ZIP, text files supported</p>
            </>
        }
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <div className="relative flex-1 min-w-48">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" />
          <input className="input pl-9" placeholder="Search evidence…" value={search} onChange={e => setSearch(e.target.value)} />
        </div>
        <select className="select w-auto min-w-40" value={typeFilter} onChange={e => setTypeFilter(e.target.value)}>
          <option value="">All Types</option>
          {Object.keys(TYPE_ICONS).map(t => (
            <option key={t} value={t}>{TYPE_ICONS[t as EvidenceType]} {t.replace(/_/g,' ')}</option>
          ))}
        </select>
      </div>

      {/* Evidence grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {filtered.map(ev => (
          <div key={ev.id} className="card p-4 flex flex-col gap-3 hover:border-slate-700 transition-colors">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 bg-slate-800 rounded-lg flex items-center justify-center text-lg flex-shrink-0">
                {TYPE_ICONS[ev.type]}
              </div>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-slate-200 truncate">{ev.title}</div>
                <div className="text-xs text-slate-500 mt-0.5 capitalize">{ev.type.replace(/_/g,' ')}</div>
              </div>
              {ev.reviewed
                ? <CheckCircle className="w-4 h-4 text-emerald-400 flex-shrink-0" />
                : <Clock className="w-4 h-4 text-amber-400 flex-shrink-0" />}
            </div>

            {ev.description && <p className="text-xs text-slate-400 line-clamp-2">{ev.description}</p>}

            {ev.control_ids.length > 0 && (
              <div className="flex flex-wrap gap-1">
                {ev.control_ids.map(id => (
                  <span key={id} className="text-xs bg-blue-600/15 text-blue-400 border border-blue-600/30 px-1.5 py-0.5 rounded font-mono">
                    {controlName(id)}
                  </span>
                ))}
              </div>
            )}

            {ev.tags.length > 0 && (
              <div className="flex flex-wrap gap-1">
                {ev.tags.map(t => <span key={t} className="text-xs bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded">#{t}</span>)}
              </div>
            )}

            <div className="flex items-center justify-between text-xs text-slate-500 pt-1 border-t border-slate-800">
              <span>{ev.file_size ? formatBytes(ev.file_size) : ''} {ev.uploaded_by ? `· ${ev.uploaded_by}` : ''}</span>
              <span>{formatDate(ev.created_at)}</span>
            </div>
          </div>
        ))}
      </div>

      {filtered.length === 0 && (
        <div className="text-center py-16 text-slate-500">No evidence matches the current filter.</div>
      )}
    </div>
  );
}
