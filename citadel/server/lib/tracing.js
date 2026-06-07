'use strict';
/* CITADEL — optional OpenTelemetry distributed tracing.
 *
 * Activated when OTEL_EXPORTER_OTLP_ENDPOINT is set (export spans to a collector
 * — Jaeger/Tempo/Datadog/Honeycomb/...) or CITADEL_TRACING=console (local debug).
 * No-ops otherwise. The OTel packages are OPTIONAL deps: the default lean image
 * omits them (build with --build-arg CITADEL_WITH_TRACING=1 to include); this
 * module degrades to a no-op with a notice if they're absent.
 *
 * MUST be required before express/http/pg so auto-instrumentation can patch them.
 */
const endpoint = process.env.OTEL_EXPORTER_OTLP_ENDPOINT || '';
const mode = (process.env.CITADEL_TRACING || '').toLowerCase();
const enabled = !!endpoint || mode === '1' || mode === 'true' || mode === 'console';

if (enabled) {
  try {
    if (!process.env.OTEL_SERVICE_NAME) process.env.OTEL_SERVICE_NAME = process.env.CITADEL_SERVICE_NAME || 'citadel';
    const { NodeSDK } = require('@opentelemetry/sdk-node');
    const { getNodeAutoInstrumentations } = require('@opentelemetry/auto-instrumentations-node');
    let traceExporter;
    if (endpoint) {
      const { OTLPTraceExporter } = require('@opentelemetry/exporter-trace-otlp-http');
      traceExporter = new OTLPTraceExporter();   // honours OTEL_EXPORTER_OTLP_ENDPOINT/HEADERS
    } else {
      const { ConsoleSpanExporter } = require('@opentelemetry/sdk-trace-base');
      traceExporter = new ConsoleSpanExporter();
    }
    const sdk = new NodeSDK({
      traceExporter,
      instrumentations: [getNodeAutoInstrumentations({
        '@opentelemetry/instrumentation-fs': { enabled: false }   // too noisy for a scanner
      })]
    });
    sdk.start();
    const shutdown = () => { try { sdk.shutdown(); } catch (e) {} };
    process.on('SIGTERM', shutdown);
    process.on('SIGINT', shutdown);
    console.log(JSON.stringify({ ts: new Date().toISOString(), level: 'info', service: process.env.OTEL_SERVICE_NAME, msg: 'tracing enabled', exporter: endpoint ? 'otlp' : 'console' }));
  } catch (e) {
    console.error(JSON.stringify({ ts: new Date().toISOString(), level: 'warn', msg: 'tracing requested but OpenTelemetry packages are not installed; continuing without tracing', err: e.message }));
  }
}

module.exports = { enabled };
