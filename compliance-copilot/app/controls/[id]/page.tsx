'use client';

import { useState, useMemo } from 'react';
import { useParams, useRouter } from 'next/navigation';
import Link from 'next/link';
import {
  ArrowLeft, FileText, CheckCircle, Edit3, Bot, ExternalLink,
  Calendar, User, Tag, BookOpen, Paperclip, Save, X
} from 'lucide-react';
import { SEED_CONTROLS, SEED_EVIDENCE } from '@/lib/data';
import { StatusBadge } from '@/components/controls/StatusBadge';
import { PriorityBadge } from '@/components/controls/PriorityBadge';
import { AIAssistantPanel } from '@/components/ai/AIAssistantPanel';
import { ControlStatus } from '@/lib/types';
import { formatDate, statusColor } from '@/lib/utils';

export default function ControlDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();

  const control = useMemo(() => SEED_CONTROLS.find(c => c.id === id), [id]);
  const evidence = useMemo(() => SEED_EVIDENCE.filter(e => e.control_ids.includes(id)), [id]);

  const [editMode, setEditMode] = useState(false);
  const [aiOpen,   setAiOpen]   = useState(false);
  const [implStmt, setImplStmt] = useState(control?.implementation_statement ?? '');
  const [notes,    setNotes]    = useState(control?.notes ?? '');
  const [status,   setStatus]   = useState<ControlStatus>(control?.status ?? 'not_implemented');

  if (!control) return (
    <div className="text-center py-20">
      <p className="text-slate-400">Control not found.</p>
      <Link href="/controls" className="btn-primary inline-block mt-4">Back to Controls</Link>
    </div>
  );

  const TABS = ['Overview', 'Implementation', 'Evidence', 'Notes'] as const;
  const [tab, setTab] = useState<typeof TABS[number]>('Overview');

  return (
    <div className="max-w-6xl mx-auto">
      {/* Back + header */}
      <div className="flex items-start justify-between mb-6 gap-4 flex-wrap">
        <div>
          <button onClick={() => router.back()} className="flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-200 mb-3">
            <ArrowLeft className="w-4 h-4" /> Back to Controls
          </button>
          <div className="flex items-center gap-3 flex-wrap">
            <span className="font-mono text-lg font-bold text-blue-400">{control.control_id}</span>
            <h1 className="text-xl font-bold text-slate-100">{control.title}</h1>
          </div>
          <div className="flex items-center gap-2 mt-2 flex-wrap">
            <span className="text-xs bg-slate-800 text-slate-300 px-2 py-0.5 rounded font-mono">{control.domain}</span>
            <span className="text-xs text-slate-500">CMMC L{control.cmmc_level}</span>
            <StatusBadge status={status} />
            <PriorityBadge priority={control.priority} />
          </div>
        </div>
        <div className="flex gap-2">
          <button className="btn-ghost flex items-center gap-2" onClick={() => setAiOpen(!aiOpen)}>
            <Bot className="w-4 h-4 text-blue-400" />
            AI Copilot
          </button>
          {editMode ? (
            <>
              <button className="btn-primary flex items-center gap-2" onClick={() => setEditMode(false)}>
                <Save className="w-4 h-4" /> Save
              </button>
              <button className="btn-secondary flex items-center gap-2" onClick={() => setEditMode(false)}>
                <X className="w-4 h-4" /> Cancel
              </button>
            </>
          ) : (
            <button className="btn-secondary flex items-center gap-2" onClick={() => setEditMode(true)}>
              <Edit3 className="w-4 h-4" /> Edit
            </button>
          )}
        </div>
      </div>

      <div className={`flex gap-6 ${aiOpen ? 'flex-col xl:flex-row' : ''}`}>
        <div className="flex-1 min-w-0 space-y-5">
          {/* Tabs */}
          <div className="border-b border-slate-800 flex gap-1">
            {TABS.map(t => (
              <button key={t} onClick={() => setTab(t)}
                className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                  tab === t ? 'border-blue-500 text-blue-400' : 'border-transparent text-slate-400 hover:text-slate-200'
                }`}>
                {t}
                {t === 'Evidence' && evidence.length > 0 &&
                  <span className="ml-1.5 text-xs bg-blue-600/20 text-blue-400 rounded-full px-1.5">{evidence.length}</span>}
              </button>
            ))}
          </div>

          {/* Overview */}
          {tab === 'Overview' && (
            <div className="space-y-4">
              <div className="card p-5">
                <div className="label flex items-center gap-2"><BookOpen className="w-3 h-3" /> Requirement</div>
                <p className="text-sm text-slate-200 leading-relaxed">{control.requirement}</p>
              </div>
              <div className="card p-5">
                <div className="label flex items-center gap-2"><FileText className="w-3 h-3" /> NIST Discussion</div>
                <p className="text-sm text-slate-300 leading-relaxed">{control.discussion}</p>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="card p-4">
                  <div className="label flex items-center gap-2 mb-3"><User className="w-3 h-3" /> Responsible Role</div>
                  <p className="text-sm text-slate-200">{control.responsible_role ?? '—'}</p>
                </div>
                <div className="card p-4">
                  <div className="label flex items-center gap-2 mb-3"><Calendar className="w-3 h-3" /> Review Dates</div>
                  <div className="text-sm space-y-1">
                    <div className="flex justify-between"><span className="text-slate-400">Last Reviewed</span><span className="text-slate-200">{formatDate(control.last_reviewed)}</span></div>
                    <div className="flex justify-between"><span className="text-slate-400">Next Review</span><span className="text-slate-200">{formatDate(control.next_review)}</span></div>
                  </div>
                </div>
              </div>
              <div className="card p-4">
                <div className="label flex items-center gap-2 mb-3"><Tag className="w-3 h-3" /> NIST SP 800-53 Mappings</div>
                <div className="flex flex-wrap gap-2">
                  {control.nist_mapping.map(m => (
                    <span key={m} className="text-xs font-mono bg-slate-800 text-slate-300 border border-slate-700 px-2 py-1 rounded">{m}</span>
                  ))}
                </div>
              </div>
              <div className="card p-4">
                <div className="label flex items-center gap-2 mb-3"><FileText className="w-3 h-3" /> Policy References</div>
                <div className="space-y-1">
                  {control.policy_references.map(p => (
                    <div key={p} className="flex items-center gap-2 text-sm text-slate-300">
                      <Paperclip className="w-3 h-3 text-slate-500 flex-shrink-0" /> {p}
                    </div>
                  ))}
                </div>
              </div>
              {editMode && (
                <div className="card p-4">
                  <label className="label mb-2">Control Status</label>
                  <select className="select" value={status} onChange={e => setStatus(e.target.value as ControlStatus)}>
                    <option value="implemented">Implemented</option>
                    <option value="partially_implemented">Partially Implemented</option>
                    <option value="not_implemented">Not Implemented</option>
                    <option value="planned">Planned</option>
                    <option value="not_applicable">Not Applicable</option>
                  </select>
                </div>
              )}
            </div>
          )}

          {/* Implementation */}
          {tab === 'Implementation' && (
            <div className="space-y-4">
              <div className="card p-5">
                <div className="flex items-center justify-between mb-3">
                  <div className="label">Implementation Statement</div>
                  {!editMode && implStmt && <CheckCircle className="w-4 h-4 text-emerald-400" />}
                </div>
                {editMode ? (
                  <textarea className="input min-h-40 resize-y text-sm leading-relaxed"
                    value={implStmt} onChange={e => setImplStmt(e.target.value)}
                    placeholder="Describe how this control is implemented in your environment…" />
                ) : (
                  implStmt
                    ? <p className="text-sm text-slate-200 leading-relaxed whitespace-pre-wrap">{implStmt}</p>
                    : <p className="text-sm text-slate-500 italic">No implementation statement recorded. Click Edit to add one, or use AI Copilot to draft one.</p>
                )}
              </div>
            </div>
          )}

          {/* Evidence */}
          {tab === 'Evidence' && (
            <div className="space-y-4">
              {evidence.length === 0 ? (
                <div className="card p-8 text-center">
                  <FileText className="w-8 h-8 text-slate-600 mx-auto mb-3" />
                  <p className="text-slate-400 text-sm">No evidence linked to this control.</p>
                  <Link href="/evidence" className="btn-primary inline-block mt-4 text-sm">Upload Evidence</Link>
                </div>
              ) : (
                evidence.map(ev => (
                  <div key={ev.id} className="card p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex items-start gap-3">
                        <div className="w-8 h-8 bg-slate-800 rounded-lg flex items-center justify-center text-sm flex-shrink-0">
                          {ev.type === 'policy' ? '📄' : ev.type === 'screenshot' ? '🖼️' : ev.type === 'configuration' ? '⚙️' : ev.type === 'test_result' ? '🔬' : ev.type === 'procedure' ? '📋' : '📁'}
                        </div>
                        <div>
                          <div className="text-sm font-medium text-slate-200">{ev.title}</div>
                          <div className="text-xs text-slate-400 mt-0.5">{ev.description}</div>
                          <div className="flex flex-wrap gap-1.5 mt-2">
                            {ev.tags.map(t => <span key={t} className="text-xs bg-slate-800 text-slate-400 px-1.5 py-0.5 rounded">#{t}</span>)}
                          </div>
                        </div>
                      </div>
                      <div className="text-right flex-shrink-0">
                        {ev.reviewed
                          ? <span className="text-xs text-emerald-400">✓ Reviewed</span>
                          : <span className="text-xs text-amber-400">Pending review</span>}
                        {ev.file_name && <div className="text-xs text-slate-500 mt-1">{ev.file_name}</div>}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>
          )}

          {/* Notes */}
          {tab === 'Notes' && (
            <div className="card p-5">
              <div className="label mb-3">Notes / POA&amp;M References</div>
              {editMode ? (
                <textarea className="input min-h-32 resize-y text-sm"
                  value={notes} onChange={e => setNotes(e.target.value)}
                  placeholder="Add notes, POA&M references, assessor findings…" />
              ) : (
                notes
                  ? <p className="text-sm text-slate-200 leading-relaxed whitespace-pre-wrap">{notes}</p>
                  : <p className="text-sm text-slate-500 italic">No notes recorded.</p>
              )}
            </div>
          )}
        </div>

        {/* AI Panel */}
        {aiOpen && (
          <div className="xl:w-96 flex-shrink-0">
            <AIAssistantPanel control={control} onClose={() => setAiOpen(false)} />
          </div>
        )}
      </div>
    </div>
  );
}
