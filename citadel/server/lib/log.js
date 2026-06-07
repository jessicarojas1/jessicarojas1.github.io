'use strict';
/* CITADEL — minimal structured (JSON-line) logger. One object per line so logs
 * are machine-parseable by any aggregator (CloudWatch, Loki, Splunk, Datadog).
 * Level via LOG_LEVEL (debug|info|warn|error), default info.
 */
const SERVICE = process.env.CITADEL_SERVICE_NAME || 'citadel';
const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };
const MIN = LEVELS[(process.env.LOG_LEVEL || 'info').toLowerCase()] || 20;

function emit(level, msg, fields) {
  if (LEVELS[level] < MIN) return;
  const rec = Object.assign({ ts: new Date().toISOString(), level, service: SERVICE, msg }, fields || {});
  let line;
  try { line = JSON.stringify(rec); } catch (e) { line = JSON.stringify({ ts: rec.ts, level, service: SERVICE, msg }); }
  if (level === 'error' || level === 'warn') process.stderr.write(line + '\n');
  else process.stdout.write(line + '\n');
}

module.exports = {
  debug: (m, f) => emit('debug', m, f),
  info: (m, f) => emit('info', m, f),
  warn: (m, f) => emit('warn', m, f),
  error: (m, f) => emit('error', m, f)
};
