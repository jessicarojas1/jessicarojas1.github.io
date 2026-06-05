import { Check } from 'lucide-react';
import { formatDate } from '@/lib/format';
import type { EightDStep, EightDStepKey } from '@/types';

const STEP_TITLES: Record<EightDStepKey, string> = {
  d1_team: 'D1 — Establish the Team',
  d2_problem: 'D2 — Describe the Problem',
  d3_containment: 'D3 — Interim Containment Actions',
  d4_root_cause: 'D4 — Root Cause Analysis',
  d5_corrective_action: 'D5 — Permanent Corrective Actions',
  d6_implementation: 'D6 — Implement & Validate',
  d7_prevention: 'D7 — Prevent Recurrence',
  d8_closure: 'D8 — Recognize Team & Close',
};

const ORDER: EightDStepKey[] = [
  'd1_team',
  'd2_problem',
  'd3_containment',
  'd4_root_cause',
  'd5_corrective_action',
  'd6_implementation',
  'd7_prevention',
  'd8_closure',
];

export function EightDStepper({ steps }: { steps?: EightDStep[] }) {
  const byKey = new Map((steps ?? []).map((s) => [s.key, s]));
  const firstIncomplete = ORDER.find((k) => !byKey.get(k)?.completed);

  return (
    <div className="stepper">
      {ORDER.map((key, idx) => {
        const step = byKey.get(key);
        const done = step?.completed ?? false;
        const active = key === firstIncomplete;
        return (
          <div key={key} className={`step ${done ? 'done' : ''} ${active ? 'active' : ''}`}>
            <div className="step__dot">{done ? <Check size={16} /> : idx + 1}</div>
            <div>
              <div className="step__title">{STEP_TITLES[key]}</div>
              {step?.completed_by && (
                <div className="step__meta">
                  Completed by {step.completed_by}
                  {step.completed_at ? ` · ${formatDate(step.completed_at)}` : ''}
                </div>
              )}
              {step?.content ? (
                <div className="step__content">{step.content}</div>
              ) : (
                <div className="step__meta muted">Not started</div>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
