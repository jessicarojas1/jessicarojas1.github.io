import { Check } from 'lucide-react';
import type { Capa, EightDStepKey } from '@/types';

const STEP_TITLES: Record<EightDStepKey, string> = {
  d1_team: 'D1 — Establish the Team',
  d2_problem_description: 'D2 — Describe the Problem',
  d3_containment: 'D3 — Interim Containment Actions',
  d4_root_cause: 'D4 — Root Cause Analysis',
  d5_corrective_action: 'D5 — Permanent Corrective Actions',
  d6_implementation: 'D6 — Implement & Validate',
  d7_preventive_action: 'D7 — Prevent Recurrence',
  d8_closure: 'D8 — Recognize Team & Close',
};

const ORDER: EightDStepKey[] = [
  'd1_team',
  'd2_problem_description',
  'd3_containment',
  'd4_root_cause',
  'd5_corrective_action',
  'd6_implementation',
  'd7_preventive_action',
  'd8_closure',
];

export function EightDStepper({ capa }: { capa: Capa }) {
  const content = (key: EightDStepKey) => capa[key];
  const firstIncomplete = ORDER.find((k) => !content(k));

  return (
    <div className="stepper">
      {ORDER.map((key, idx) => {
        const text = content(key);
        const done = Boolean(text);
        const active = key === firstIncomplete;
        return (
          <div key={key} className={`step ${done ? 'done' : ''} ${active ? 'active' : ''}`}>
            <div className="step__dot">{done ? <Check size={16} /> : idx + 1}</div>
            <div>
              <div className="step__title">{STEP_TITLES[key]}</div>
              {text ? (
                <div className="step__content">{text}</div>
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
