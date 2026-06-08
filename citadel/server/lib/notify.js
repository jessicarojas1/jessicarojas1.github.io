'use strict';
/* CITADEL — scan-completion notifications (Slack/Teams/generic webhook).
 *
 * When CITADEL_NOTIFY_URL is set, a summary is POSTed after each scan whose
 * worst severity meets CITADEL_NOTIFY_ON (critical|high|medium|low|any,
 * default 'critical'). The payload is Slack-compatible ({ text, ... }) and also
 * carries structured fields for generic consumers. Fire-and-forget — never
 * affects the scan response.
 */
const URL_ = process.env.CITADEL_NOTIFY_URL || '';
const TOKEN = process.env.CITADEL_NOTIFY_TOKEN || '';
const ON = (process.env.CITADEL_NOTIFY_ON || 'critical').toLowerCase();
const SERVICE = process.env.CITADEL_SERVICE_NAME || 'citadel';
const RANK = { info: 0, low: 1, medium: 2, high: 3, critical: 4 };

function enabled() { return !!URL_; }

function worstRank(sev) {
  return ['critical', 'high', 'medium', 'low', 'info']
    .reduce((m, s) => (sev[s] ? Math.max(m, RANK[s]) : m), 0);
}

function scanComplete(report, opts) {
  opts = opts || {};
  if (!URL_ || typeof fetch !== 'function' || !report) return;
  const s = report.scoring || {};
  const sev = s.sev || {};
  const threshold = ON === 'any' ? -1 : (RANK[ON] != null ? RANK[ON] : 4);
  if (worstRank(sev) < threshold) return;   // below the notify threshold

  const src = opts.source || (report.meta && report.meta.source) || 'scan';
  const who = opts.user && opts.user.email ? ' · by ' + opts.user.email : '';
  const bits = ['critical', 'high', 'medium'].filter(k => sev[k]).map(k => sev[k] + ' ' + k);
  const text = `:shield: *CITADEL scan* — \`${src}\` — grade *${s.grade || '?'}* ` +
    `(security ${s.security | 0}/100), ${(report.findings || []).length} findings` +
    (bits.length ? ' (' + bits.join(', ') + ')' : '') + who;

  const headers = { 'Content-Type': 'application/json' };
  if (TOKEN) headers.Authorization = 'Bearer ' + TOKEN;
  const body = {
    text,                                   // Slack / Teams legacy connector
    service: SERVICE, source: src,
    grade: s.grade, security: s.security | 0,
    findings: (report.findings || []).length, severities: sev,
    user: opts.user && opts.user.email || null,
    at: new Date().toISOString()
  };
  Promise.resolve()
    .then(() => fetch(URL_, { method: 'POST', headers, body: JSON.stringify(body) }))
    .catch(() => {});
}

module.exports = { scanComplete, enabled };
