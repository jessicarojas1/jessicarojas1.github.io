// Minimal structured logger for API route handlers.
//
// Emits one JSON object per line to stdout (info/debug) or stderr (warn/error)
// so platform log collectors (Render, CloudWatch, Loki, journald) can parse and
// index fields without a heavyweight logging dependency. Every log line carries
// an ISO timestamp, a level, a message, and any structured fields the caller
// attaches (request id, route, status, latency, identity, …).
//
// NEVER log secrets (ANTHROPIC_API_KEY, AI_PROXY_TOKEN, service-role key),
// prompt/response bodies, or raw credentials — attach only non-sensitive
// metadata (ids, counts, statuses, durations).

export type LogLevel = 'debug' | 'info' | 'warn' | 'error';

export type LogFields = Record<string, unknown>;

const LEVEL_RANK: Record<LogLevel, number> = { debug: 10, info: 20, warn: 30, error: 40 };

// LOG_LEVEL env var gates output (default: debug in dev, info in prod).
function minLevel(): number {
  const configured = (process.env.LOG_LEVEL || '').toLowerCase() as LogLevel;
  if (configured in LEVEL_RANK) return LEVEL_RANK[configured];
  return process.env.NODE_ENV === 'production' ? LEVEL_RANK.info : LEVEL_RANK.debug;
}

function emit(level: LogLevel, message: string, fields?: LogFields): void {
  if (LEVEL_RANK[level] < minLevel()) return;
  const line = JSON.stringify({
    ts: new Date().toISOString(),
    level,
    msg: message,
    ...fields,
  });
  // eslint-disable-next-line no-console
  if (level === 'error' || level === 'warn') console.error(line);
  // eslint-disable-next-line no-console
  else console.log(line);
}

export const logger = {
  debug: (message: string, fields?: LogFields) => emit('debug', message, fields),
  info: (message: string, fields?: LogFields) => emit('info', message, fields),
  warn: (message: string, fields?: LogFields) => emit('warn', message, fields),
  error: (message: string, fields?: LogFields) => emit('error', message, fields),
};

// Derive a request id from an inbound header when present (so a gateway/WAF can
// correlate), otherwise mint one. Uses the Web Crypto UUID available in both the
// Node and Edge runtimes.
export function requestId(headers: Headers): string {
  const forwarded = headers.get('x-request-id') || headers.get('x-correlation-id');
  if (forwarded && forwarded.length <= 200) return forwarded;
  try {
    return crypto.randomUUID();
  } catch {
    return `req_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
  }
}

// Bind a request id (and any base fields) so a handler can log with context
// without repeating the id on every call.
export function withRequestId(id: string, base?: LogFields) {
  const merged = (fields?: LogFields): LogFields => ({ req_id: id, ...base, ...fields });
  return {
    id,
    debug: (message: string, fields?: LogFields) => logger.debug(message, merged(fields)),
    info: (message: string, fields?: LogFields) => logger.info(message, merged(fields)),
    warn: (message: string, fields?: LogFields) => logger.warn(message, merged(fields)),
    error: (message: string, fields?: LogFields) => logger.error(message, merged(fields)),
  };
}

export type RequestLogger = ReturnType<typeof withRequestId>;
