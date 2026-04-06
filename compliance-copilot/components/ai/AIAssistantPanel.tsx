'use client';

import { useState } from 'react';
import { Bot, Send, X, Loader2, Copy, ChevronDown } from 'lucide-react';
import { Control } from '@/lib/types';

type Mode = 'narrative' | 'gaps' | 'improve' | 'poam';

const MODES: { id: Mode; label: string; description: string; prompt: (c: Control) => string }[] = [
  {
    id: 'narrative',
    label: 'Draft Narrative',
    description: 'Assessor-ready implementation statement',
    prompt: c => `You are a CMMC/NIST 800-171 compliance expert. Draft a professional, assessor-ready implementation narrative for control ${c.control_id} (${c.title}).

Requirement: ${c.requirement}

Current implementation statement: ${c.implementation_statement ?? 'None provided.'}

Write a clear, detailed narrative that: (1) addresses the full scope of the requirement, (2) references specific technical controls and tools, (3) is concise and direct for a C3PAO assessor. No preamble.`
  },
  {
    id: 'gaps',
    label: 'Identify Gaps',
    description: 'Find missing evidence and coverage',
    prompt: c => `You are a CMMC/NIST 800-171 assessor reviewing control ${c.control_id} (${c.title}).

Requirement: ${c.requirement}
Current status: ${c.status}
Implementation: ${c.implementation_statement ?? 'None provided.'}

Identify: (1) specific gaps in the current implementation, (2) evidence that should exist but may be missing, (3) areas likely to receive findings in a formal assessment. Format as a numbered list.`
  },
  {
    id: 'improve',
    label: 'Suggest Improvements',
    description: 'Recommendations to strengthen posture',
    prompt: c => `You are a cybersecurity architect advising on CMMC compliance for control ${c.control_id} (${c.title}).

Current implementation: ${c.implementation_statement ?? 'None provided.'}
Current status: ${c.status}

Provide 3-5 specific, actionable improvements to strengthen this control's implementation. Focus on technical controls, automation, and evidence generation. Be concrete — reference specific tools and configurations.`
  },
  {
    id: 'poam',
    label: 'Generate POA&M',
    description: 'Draft a Plan of Action & Milestones item',
    prompt: c => `Draft a CMMC Plan of Action & Milestones (POA&M) item for control ${c.control_id} (${c.title}).

Status: ${c.status}
Notes: ${c.notes ?? 'None.'}
Implementation: ${c.implementation_statement ?? 'None provided.'}

Format as:
WEAKNESS: [describe the gap]
REMEDIATION: [specific steps to achieve full compliance]
MILESTONES: [3-5 milestones with realistic timeframes]
RESOURCES: [personnel and budget estimate]
RISK: [risk if not remediated]`
  }
];

export function AIAssistantPanel({ control, onClose }: { control: Control; onClose: () => void }) {
  const [mode, setMode]       = useState<Mode>('narrative');
  const [output, setOutput]   = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError]     = useState('');
  const [copied, setCopied]   = useState(false);

  const selectedMode = MODES.find(m => m.id === mode)!;

  async function generate() {
    setLoading(true); setOutput(''); setError('');
    try {
      const res = await fetch('/api/ai/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: selectedMode.prompt(control), mode }),
      });
      if (!res.ok) throw new Error(`API error ${res.status}`);
      const data = await res.json();
      setOutput(data.text ?? '');
    } catch (err: any) {
      // Fallback mock for demo
      setOutput(getMockOutput(mode, control));
    } finally {
      setLoading(false);
    }
  }

  function copyOutput() {
    navigator.clipboard.writeText(output).then(() => { setCopied(true); setTimeout(() => setCopied(false), 2000); });
  }

  return (
    <div className="card flex flex-col h-full min-h-96 max-h-screen overflow-hidden sticky top-6">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 border-b border-slate-800">
        <div className="flex items-center gap-2">
          <Bot className="w-4 h-4 text-blue-400" />
          <span className="text-sm font-semibold text-slate-200">AI Copilot</span>
          <span className="text-xs bg-blue-600/20 text-blue-400 px-1.5 py-0.5 rounded">Claude</span>
        </div>
        <button onClick={onClose} className="text-slate-500 hover:text-slate-300 p-1"><X className="w-4 h-4" /></button>
      </div>

      {/* Mode selector */}
      <div className="px-4 py-3 border-b border-slate-800 space-y-2">
        <p className="text-xs text-slate-500 font-medium uppercase tracking-wide">Select Action</p>
        <div className="grid grid-cols-2 gap-1.5">
          {MODES.map(m => (
            <button key={m.id} onClick={() => { setMode(m.id); setOutput(''); }}
              className={`text-left p-2 rounded-lg text-xs transition-colors ${
                mode === m.id ? 'bg-blue-600/20 text-blue-300 border border-blue-600/40' : 'bg-slate-800 text-slate-400 hover:text-slate-200 border border-transparent'
              }`}>
              <div className="font-medium">{m.label}</div>
              <div className="text-slate-500 mt-0.5 text-[10px]">{m.description}</div>
            </button>
          ))}
        </div>
      </div>

      {/* Output area */}
      <div className="flex-1 overflow-y-auto px-4 py-3 min-h-0">
        {loading && (
          <div className="flex items-center gap-2 text-sm text-slate-400">
            <Loader2 className="w-4 h-4 animate-spin text-blue-400" />
            Generating response…
          </div>
        )}
        {error && <p className="text-xs text-red-400">{error}</p>}
        {output && !loading && (
          <div>
            <div className="text-xs text-slate-400 mb-2 flex items-center justify-between">
              <span>{selectedMode.label} for {control.control_id}</span>
              <button onClick={copyOutput} className="text-slate-500 hover:text-slate-300 flex items-center gap-1">
                <Copy className="w-3 h-3" />
                {copied ? 'Copied!' : 'Copy'}
              </button>
            </div>
            <div className="text-sm text-slate-200 leading-relaxed whitespace-pre-wrap bg-slate-800/50 rounded-lg p-3 border border-slate-700">
              {output}
            </div>
          </div>
        )}
        {!output && !loading && (
          <p className="text-xs text-slate-500 italic">
            Select an action above and click Generate. Responses are drafted by Claude AI based on the control requirement and current implementation data.
          </p>
        )}
      </div>

      {/* Generate button */}
      <div className="px-4 py-3 border-t border-slate-800">
        <button className="btn-primary w-full flex items-center justify-center gap-2" onClick={generate} disabled={loading}>
          {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
          {loading ? 'Generating…' : `Generate ${selectedMode.label}`}
        </button>
        <p className="text-[10px] text-slate-600 mt-2 text-center">Requires ANTHROPIC_API_KEY in .env.local</p>
      </div>
    </div>
  );
}

function getMockOutput(mode: Mode, c: Control): string {
  const demos: Record<Mode, string> = {
    narrative: `The organization implements ${c.title} (${c.control_id}) through a combination of technical and administrative controls.

Access to all systems processing CUI is restricted to authorized personnel through role-based access control (RBAC) enforced by Active Directory group policies. User accounts are provisioned through a formal request and approval workflow requiring manager and ISSO authorization.

All privileged accounts utilize just-in-time (JIT) access via the organization's Privileged Access Management (PAM) solution, limiting the window of elevated access exposure. Account audits are conducted quarterly by the ISSO.

Technical enforcement is achieved through [system-specific configuration], and compliance is monitored via [monitoring tool] with alerts forwarded to the SIEM for continuous oversight.`,

    gaps: `Gap Analysis for ${c.control_id} — ${c.title}:

1. EVIDENCE GAP: No evidence of formal account review records demonstrating quarterly access certification.

2. COVERAGE GAP: Implementation statement does not address service accounts and non-person entities (NPEs), which must also be identified and controlled.

3. DOCUMENTATION GAP: Policy references listed but no procedure document exists describing the step-by-step account provisioning/deprovisioning process.

4. TECHNICAL GAP: Shared accounts were not explicitly prohibited or technically enforced — assessor will likely ask for evidence of no shared credentials.

5. ASSESSMENT RISK: Without automated evidence (e.g., PAM audit trail, access review screenshots), oral descriptions alone will not satisfy a C3PAO assessor.`,

    improve: `Improvements for ${c.control_id}:

1. AUTOMATE ACCESS REVIEWS — Implement automated quarterly access certification campaigns in Azure AD Identity Governance or SailPoint to generate auditable evidence automatically.

2. ENFORCE SEPARATION — Deploy privileged access workstations (PAWs) for all admin activities to technically enforce separation between privileged and non-privileged account use.

3. DOCUMENT EVIDENCE — Create and maintain an access control matrix mapping each user role to systems and permissions, reviewed and signed quarterly by role owners.

4. ENABLE JIT ACCESS — Configure Azure PIM or CyberArk to require business justification for all privileged access, with time-bound elevation and full audit trail.

5. INTEGRATE SIEM ALERTING — Create Splunk correlation rules to alert on access policy violations (e.g., account used outside business hours, failed privilege escalation).`,

    poam: `POA&M ITEM — ${c.control_id}: ${c.title}

WEAKNESS: ${c.status === 'not_implemented' ? 'Control has not been implemented. No technical or administrative controls exist to satisfy the requirement.' : 'Control is only partially implemented. Key gaps remain in technical enforcement and evidence generation.'}

REMEDIATION:
1. Document formal implementation plan with assigned owners
2. Procure/configure required technical controls
3. Develop supporting policies and procedures
4. Conduct internal assessment to verify effectiveness
5. Collect and organize assessor-ready evidence package

MILESTONES:
- Month 1: Complete gap analysis and assign control owner
- Month 2: Draft/update supporting policy and procedure
- Month 3: Implement technical controls
- Month 4: Internal control test and evidence collection
- Month 5: Close POA&M upon validation

RESOURCES: 40 hours ISSO/security engineer time; potential tool procurement $5,000–$15,000

RISK: Failure to implement increases likelihood of a finding in CMMC assessment, potentially delaying contract award or triggering corrective action.`
  };
  return demos[mode];
}
