import type { ApqpPhase } from '@/types';

export const PHASE_LABELS: Record<ApqpPhase, string> = {
  planning: '1 · Planning',
  product_design: '2 · Product Design',
  process_design: '3 · Process Design',
  validation: '4 · Validation',
  production: '5 · Production',
};
