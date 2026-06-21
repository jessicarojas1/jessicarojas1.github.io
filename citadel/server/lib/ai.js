'use strict';
/* CITADEL backend — AI-assisted remediation.
 * Optional: only active when ANTHROPIC_API_KEY is set and the SDK is installed.
 * Given a finding, asks Claude for a concise explanation + a concrete fix.
 * Uses the official @anthropic-ai/sdk with claude-opus-4-8 (adaptive thinking).
 */
let Anthropic = null;
try { Anthropic = require('@anthropic-ai/sdk'); } catch (e) { /* SDK not installed — feature disabled */ }

const MODEL = process.env.CITADEL_AI_MODEL || 'claude-opus-4-8';

// Air-gap / no-egress profile: when CITADEL_AIRGAP=1 (or CITADEL_NO_EGRESS=1),
// AI remediation is hard-disabled so scanned source code can NEVER be transmitted
// to an external LLM — required when reviewing CUI / ITAR / export-controlled /
// proprietary code. Egress is opt-in (needs ANTHROPIC_API_KEY) AND not air-gapped.
function airgapped() {
  return process.env.CITADEL_AIRGAP === '1' || process.env.CITADEL_NO_EGRESS === '1';
}
function available() {
  return !airgapped() && !!(Anthropic && process.env.ANTHROPIC_API_KEY);
}

let _client = null;
function client() {
  if (!_client) _client = new Anthropic(); // reads ANTHROPIC_API_KEY from env
  return _client;
}

function buildPrompt(finding) {
  const f = finding || {};
  return [
    'You are a senior application-security engineer. A static analysis tool flagged the finding below.',
    'Explain the risk in 2-3 sentences, then give a concrete, minimal fix. If a code change helps, show a short before/after snippet in a fenced block. Keep the whole answer under 220 words. Do not invent file contents you were not given.',
    '',
    `Finding: ${f.name || 'unknown'}`,
    `Severity: ${f.severity || 'unknown'}`,
    `Category: ${f.category || 'unknown'}`,
    f.cwe ? `Weakness: ${f.cwe}` : '',
    f.file ? `Location: ${f.file}${f.line ? ':' + f.line : ''}` : '',
    f.snippet ? `Flagged code:\n${String(f.snippet).slice(0, 600)}` : '',
    f.remediation ? `Tool guidance: ${f.remediation}` : ''
  ].filter(Boolean).join('\n');
}

async function explain(finding) {
  if (!available()) throw new Error('AI remediation is not configured (set ANTHROPIC_API_KEY and install @anthropic-ai/sdk).');
  const resp = await client().messages.create({
    model: MODEL,
    max_tokens: 1200,
    thinking: { type: 'adaptive' },
    output_config: { effort: 'medium' },
    messages: [{ role: 'user', content: buildPrompt(finding) }]
  });
  const text = (resp.content || []).filter(b => b.type === 'text').map(b => b.text).join('\n').trim();
  return { model: MODEL, text: text || 'No explanation produced.' };
}

module.exports = { available, airgapped, explain, MODEL };
