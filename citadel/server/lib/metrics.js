'use strict';
/* CITADEL — Prometheus metrics (no dependency). Counters + gauges rendered in
 * the text exposition format at GET /metrics, scrapeable by Prometheus/Grafana.
 */
const _counters = new Map();   // "name{labels}" -> value
const _gauges = {};            // name -> () => number

function labelStr(labels) {
  if (!labels) return '';
  const parts = Object.keys(labels).sort().map(k => `${k}="${String(labels[k]).replace(/[\\"\n]/g, '_')}"`);
  return parts.length ? `{${parts.join(',')}}` : '';
}
function inc(name, labels, by) {
  const key = name + labelStr(labels);
  _counters.set(key, (_counters.get(key) || 0) + (by == null ? 1 : by));
}
function gauge(name, fn) { _gauges[name] = fn; }

function render() {
  let out = '';
  const typed = new Set();
  for (const [key, val] of _counters) {
    const base = key.split('{')[0];
    if (!typed.has(base)) { out += `# TYPE ${base} counter\n`; typed.add(base); }
    out += `${key} ${val}\n`;
  }
  for (const [name, fn] of Object.entries(_gauges)) {
    let v; try { v = fn(); } catch (e) { v = 0; }
    out += `# TYPE ${name} gauge\n${name} ${Number(v) || 0}\n`;
  }
  return out;
}

// Express middleware: count every response by method + status class.
function httpMiddleware() {
  return (req, res, next) => {
    const orig = res.end;
    res.end = function (...args) {
      try { inc('citadel_http_requests_total', { method: req.method, status: res.statusCode }); } catch (e) {}
      return orig.apply(this, args);
    };
    next();
  };
}

module.exports = { inc, gauge, render, httpMiddleware };
