# CITADEL — observability reference assets

Reference **Grafana dashboard** and **Prometheus alert rules** for CITADEL,
built against the metrics the app exposes at `GET /metrics`
(`server/lib/metrics.js`). These are drop-in starting points — tune thresholds
to your SLOs and provisioned resources.

## Files

| File | Purpose |
| --- | --- |
| `grafana-dashboard.json` | Importable Grafana dashboard: instances up, active sessions, RSS, 5xx ratio, request rate by status, scans vs. scan errors, logins. |
| `prometheus-alerts.yml` | Alert rules: instance down, crash-loop, high 5xx, elevated scan errors, high memory / OOM risk, active-session spike. |

## Metrics referenced (all real, emitted by CITADEL)

| Metric | Type | Labels |
| --- | --- | --- |
| `citadel_http_requests_total` | counter | `method`, `status` |
| `citadel_logins_total` | counter | `result` (`success`/`failure`/`mfa_failure`/`sso`) |
| `citadel_scans_total` | counter | — |
| `citadel_scan_errors_total` | counter | — |
| `citadel_active_sessions` | gauge | — |
| `citadel_uptime_seconds` | gauge | — |
| `citadel_resident_memory_bytes` | gauge | — |

## Scrape config

`/metrics` requires a Bearer token when `CITADEL_METRICS_TOKEN` is set, otherwise
it is loopback-only (see `docs/DEPLOYMENT.md`, `docs/ENV.md`). Label the job
`citadel` so the rules' `job=~"citadel.*"` matches:

```yaml
scrape_configs:
  - job_name: citadel
    metrics_path: /metrics
    authorization:
      credentials: "${CITADEL_METRICS_TOKEN}"
    static_configs:
      - targets: ["citadel:8080"]

rule_files:
  - /etc/prometheus/rules/citadel-alerts.yml
```

## Import the dashboard

Grafana → Dashboards → New → Import → upload `grafana-dashboard.json`, then pick
your Prometheus data source for the `DS_PROMETHEUS` input.

## Known gap

Per-scan **latency** is not yet exported as a histogram (metrics are counters +
gauges only), so no scan-latency panel/alert is included. Adding a
`citadel_scan_duration_seconds` histogram is tracked in
[`../../OPEN_ITEMS.md`](../../OPEN_ITEMS.md).
