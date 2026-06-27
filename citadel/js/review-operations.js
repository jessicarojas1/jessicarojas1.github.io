/* CITADEL — Release Readiness Reviewer: Operational Readiness
 * Heuristically determines whether the project ships the OPERATIONAL controls a
 * service needs to be run safely in production: health / readiness endpoints
 * (so an orchestrator can tell if it is alive), monitoring / observability
 * (metrics, tracing, error reporting), alerting (someone is paged when it
 * breaks), backup of persistent data, a documented restore procedure, and a
 * disaster-recovery / business-continuity reference. Emits release-readiness
 * findings for the MISSING areas.
 *
 * Pure, defensive, offline. Never throws — every detector is wrapped in
 * try/catch and degrades to an empty result. No network / DOM / Date access, so
 * it is safe inside the scan pipeline AND a Web Worker. Only text entries
 * (content && !isBinary) are scanned; very large files are sliced to ~200KB
 * before regex. Mirrors js/review-logging.js and js/review-testing.js
 * conventions (IIFE on window.CITADEL, curated lists, scoring formula
 * documented in comments).
 *
 * These are HEURISTIC release-readiness checks, not certification — confidence
 * is intentionally mostly LOW/MEDIUM and compliance language is phrased
 * non-false-certain ('Potential evidence gap' etc.).
 *
 * window.CITADEL.reviewOperations
 *   analyze(entries, report)
 *     -> { findings:[Finding], summary:{
 *          healthEndpoints:[string], monitoring:[string], alerting:boolean,
 *          backup:boolean, restore:boolean, dr:boolean, score:0..100 } }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX_SCAN_BYTES = 200 * 1024; // slice very large files before regex.

  // ===========================================================================
  // Curated reference lists — intentionally SMALL and ILLUSTRATIVE, tuned for
  // high-signal offline detection. Not exhaustive.
  // ===========================================================================

  // App-level health / readiness / liveness route paths. Each maps a path-ish
  // RegExp to the canonical label we surface in summary.healthEndpoints.
  const HEALTH_PATHS = [
    { label: '/health', re: /['"`]\/health(?:[/'"`?]|$)/i },
    { label: '/healthz', re: /['"`]\/healthz(?:[/'"`?]|$)/i },
    { label: '/healthcheck', re: /['"`]\/health[-_]?check(?:[/'"`?]|$)/i },
    { label: '/ready', re: /['"`]\/ready(?:[/'"`?]|$)/i },
    { label: '/readyz', re: /['"`]\/readyz(?:[/'"`?]|$)/i },
    { label: '/readiness', re: /['"`]\/readiness(?:[/'"`?]|$)/i },
    { label: '/livez', re: /['"`]\/livez(?:[/'"`?]|$)/i },
    { label: '/live', re: /['"`]\/live(?:ness)?(?:[/'"`?]|$)/i },
    { label: '/healthy', re: /['"`]\/-\/healthy(?:[/'"`?]|$)/i },
    { label: '/ping', re: /['"`]\/ping(?:[/'"`?]|$)/i },
    { label: '/status', re: /['"`]\/status(?:[/'"`?]|$)/i }
  ];

  // A line/file looks like a route DEFINITION (Express/Flask/etc.) — anchors the
  // health-path patterns so we do not match arbitrary mentions of '/status'.
  const ROUTE_DEF_RE = new RegExp(
    [
      // JS: app.get('/x'), router.get(, fastify.get(, .route('/x')
      '\\b(?:app|router|server|fastify|api|route[sr]?)\\s*\\.\\s*(?:get|post|all|use|route)\\s*\\(',
      '\\.route\\s*\\(',
      // Flask / FastAPI / Django
      '@(?:app|router|api|blueprint)\\.(?:get|post|route|api_route)\\s*\\(',
      '\\bpath\\s*\\(', '\\burl\\s*\\(', '\\b(?:add_url_rule|add_route)\\s*\\(',
      // Express middleware mount of a healthcheck lib
      '\\b(?:healthcheck|terminus|actuator|health)\\b'
    ].join('|'),
    'i'
  );

  // Kubernetes probes (manifest yaml).
  const K8S_PROBE = [
    { label: 'k8s:livenessProbe', re: /\blivenessProbe\s*:/ },
    { label: 'k8s:readinessProbe', re: /\breadinessProbe\s*:/ },
    { label: 'k8s:startupProbe', re: /\bstartupProbe\s*:/ }
  ];

  // Dockerfile HEALTHCHECK + compose healthcheck. (The container reviewer also
  // covers these — kept at LOW confidence here to avoid noisy duplication.)
  const DOCKER_HEALTHCHECK_RE = /^\s*HEALTHCHECK\b/im;
  const COMPOSE_HEALTHCHECK_RE = /^\s*healthcheck\s*:/im;

  // Monitoring / observability tools. label is the canonical token surfaced in
  // summary.monitoring.
  const MONITORING = [
    { label: 'prometheus', re: /\b(?:prometheus|prom-client|prometheus_client|prometheus-net|micrometer)\b/i },
    { label: 'prometheus', re: /['"`]\/metrics(?:[/'"`?]|$)/i }, // /metrics endpoint => prometheus-style.
    { label: 'opentelemetry', re: /@opentelemetry\b|\bopentelemetry\b|\botel\b/i },
    { label: 'datadog', re: /\bdd-trace\b|\bdatadog\b|\bdd_agent\b|\bDD_API_KEY\b/i },
    { label: 'newrelic', re: /\bnewrelic\b|\bnew[ _-]?relic\b|\bNEW_RELIC_/i },
    { label: 'sentry', re: /@sentry\b|\bsentry-sdk\b|\bSENTRY_DSN\b|\bSentry\.init\b/i },
    { label: 'statsd', re: /\bstatsd\b|\bhot-shots\b|\bnode-statsd\b/i },
    { label: 'grafana', re: /\bgrafana\b/i },
    { label: 'elastic-apm', re: /\belastic[ _-]?apm\b|\belastic-apm-node\b/i },
    { label: 'cloudwatch', re: /\bcloudwatch\b|\baws-embedded-metrics\b|\bput_metric_data\b/i }
  ];

  // Alerting signals.
  const ALERTING_RE = new RegExp(
    [
      '\\balertmanager\\b', '\\balerting\\b', '\\balert_?rules\\b', '\\balerts?\\s*:',
      '\\bpagerduty\\b', '\\bopsgenie\\b', '\\bvictorops\\b',
      '\\bPAGERDUTY_', '\\bOPSGENIE_',
      // Slack / Teams used as an alert/notification webhook.
      '\\bSLACK_(?:WEBHOOK|ALERT)', '\\bTEAMS_WEBHOOK',
      'hooks\\.slack\\.com', 'outlook\\.office\\.com/webhook',
      '\\bnotification[ _-]?(?:webhook|channel)\\b'
    ].join('|'),
    'i'
  );

  // Backup signals.
  const BACKUP_RE = new RegExp(
    [
      '\\bpg_dump\\b', '\\bmysqldump\\b', '\\bmongodump\\b', '\\bpg_basebackup\\b',
      '\\bvelero\\b', '\\brestic\\b', '\\bborgbackup\\b', '\\bduplicity\\b',
      '\\bsnapshot[s]?\\b', '\\bvolumeSnapshot\\b',
      '\\bbackup\\b', '\\bbackups\\b',
      // S3/GCS backup buckets.
      'backup[-_]?bucket', '\\b(?:s3|gcs|gsutil)\\b[^\\n]*backup'
    ].join('|'),
    'i'
  );
  // A path whose basename strongly implies backup (script / job / artifact).
  const BACKUP_PATH_RE = /(?:^|\/)[^/]*backup[^/]*\.(?:sh|bash|ps1|py|js|ts|yml|yaml|sql|bak)$|\.bak$|(?:^|\/)backups?\//i;

  // Restore signals (procedure / docs / commands).
  const RESTORE_RE = new RegExp(
    [
      '\\bpg_restore\\b', '\\bmongorestore\\b', '\\bmysql\\s+<', '\\brestore\\b',
      '\\brecover(?:y)?\\b', '\\broll[ _-]?back\\b'
    ].join('|'),
    'i'
  );
  const RESTORE_PATH_RE = /(?:^|\/)[^/]*(?:restore|recover|runbook)[^/]*\.(?:md|txt|rst|sh|bash|ps1|py|js|ts|yml|yaml)$/i;

  // Disaster-recovery / continuity signals.
  const DR_RE = new RegExp(
    [
      '\\bdisaster[ _-]?recovery\\b', '\\bdisaster[ _-]?recover\\b', '\\bdr[ _-]?plan\\b',
      '\\bfail[ _-]?over\\b', '\\bfailover\\b', '\\bmulti[ _-]?region\\b', '\\bmulti[ _-]?az\\b',
      '\\bactive[ _-]?(?:active|passive)\\b',
      '\\brto\\b', '\\brpo\\b', '\\brunbook\\b',
      '\\bbusiness[ _-]?continuity\\b', '\\bcontinuity[ _-]?plan\\b', '\\bbcp\\b'
    ].join('|'),
    'i'
  );

  // ===========================================================================
  // Compliance mappings — phrased non-false-certain. Selected per finding.
  // ===========================================================================

  const C = {
    health: [
      { framework: 'NIST SP 800-53', control: 'SI-4', note: 'Potential evidence gap: system monitoring relies on health/liveness signals.' },
      { framework: 'SOC 2', control: 'A1.2', note: 'Potential evidence gap: availability of monitored, recoverable components.' },
      { framework: 'ISO 27001', control: 'A.8.16', note: 'Potential evidence gap: monitoring activities for systems.' }
    ],
    monitoring: [
      { framework: 'NIST SP 800-53', control: 'SI-4', note: 'Potential evidence gap: information system monitoring.' },
      { framework: 'SOC 2', control: 'CC7.2', note: 'Potential evidence gap: monitoring to detect anomalies/incidents.' },
      { framework: 'ISO 27001', control: 'A.8.16', note: 'Potential evidence gap: monitoring activities.' }
    ],
    alerting: [
      { framework: 'NIST SP 800-53', control: 'SI-4', note: 'Potential evidence gap: alerting on monitored security/availability events.' },
      { framework: 'SOC 2', control: 'CC7.3', note: 'Potential evidence gap: evaluation/notification of detected events.' },
      { framework: 'ISO 27001', control: 'A.8.16', note: 'Potential evidence gap: monitoring with timely response.' }
    ],
    backup: [
      { framework: 'NIST SP 800-53', control: 'CP-9', note: 'Potential evidence gap: information system backup.' },
      { framework: 'SOC 2', control: 'A1.2', note: 'Potential evidence gap: data backup processes for availability.' },
      { framework: 'ISO 27001', control: 'A.8.13', note: 'Potential evidence gap: information backup.' }
    ],
    restore: [
      { framework: 'NIST SP 800-53', control: 'CP-10', note: 'Potential evidence gap: information system recovery and reconstitution.' },
      { framework: 'SOC 2', control: 'A1.3', note: 'Potential evidence gap: recovery testing of backups.' },
      { framework: 'ISO 27001', control: 'A.8.13', note: 'Potential evidence gap: backup restoration capability.' }
    ],
    dr: [
      { framework: 'NIST SP 800-53', control: 'CP-2', note: 'Potential evidence gap: contingency/continuity planning.' },
      { framework: 'SOC 2', control: 'A1.3', note: 'Potential evidence gap: business continuity / disaster recovery.' },
      { framework: 'ISO 27001', control: 'A.5.30', note: 'Potential evidence gap: ICT readiness for business continuity.' }
    ]
  };

  const REFERENCES = [
    'https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/',
    'https://opentelemetry.io/docs/',
    'https://csrc.nist.gov/pubs/sp/800/53/r5/upd1/final'
  ];

  // ===========================================================================
  // Helpers
  // ===========================================================================

  function asArray(v) { return Array.isArray(v) ? v : []; }

  function scanText(e) {
    // Return scannable text for an entry, or null if not a text entry.
    if (!e || typeof e.content !== 'string' || e.content === '') return null;
    if (e.isBinary) return null;
    const c = e.content;
    return c.length > MAX_SCAN_BYTES ? c.slice(0, MAX_SCAN_BYTES) : c;
  }

  function basename(path) {
    return String(path == null ? '' : path).split(/[\\/]/).pop();
  }

  function isYaml(path) { return /\.ya?ml$/i.test(String(path || '')); }
  function isDockerfile(path) {
    const b = basename(path).toLowerCase();
    return b === 'dockerfile' || /^dockerfile\b/.test(b) || /\.dockerfile$/i.test(b);
  }
  function isCompose(path) {
    return /(?:^|\/)(?:docker-)?compose(?:\.[\w-]+)?\.ya?ml$/i.test(String(path || ''));
  }

  // Find the 1-based line number of the first match of `re` in `text`.
  function lineOf(text, re) {
    try {
      const m = re.exec(text);
      if (!m) return null;
      return text.slice(0, m.index).split('\n').length;
    } catch (e) { return null; }
  }

  function pushUnique(arr, v) {
    if (arr.indexOf(v) < 0) arr.push(v);
  }

  // ===========================================================================
  // report introspection — does the app clearly have a database / persistent
  // store? Defensive about a partial/absent report.
  // ===========================================================================

  // Dependency / source DB driver evidence (fallback when the report lacks a
  // runtime block).
  const DB_DRIVER_RE = /\b(?:pg|postgres(?:ql)?|mysql2?|mariadb|sqlite3?|better-sqlite3|mongoose|mongodb|sequelize|typeorm|prisma|knex|redis|ioredis|psycopg2?|sqlalchemy|pymysql|pymongo|gorm|database\/sql|jdbc|hibernate|spring-data|doctrine|pdo_pgsql|pdo_mysql)\b/i;

  function reportHasDatabase(report, entries) {
    try {
      const dep = (report && report.depreview) || {};
      const rt = dep.runtime || {};
      const dbs = asArray(rt.databases);
      if (dbs.length) return true;
      // services array may include DB-like services (engine/type/name strings).
      const svcs = asArray(rt.services);
      for (let i = 0; i < svcs.length; i++) {
        const s = svcs[i];
        const blob = typeof s === 'string' ? s : JSON.stringify(s || {});
        if (DB_DRIVER_RE.test(blob)) return true;
      }
      // stack.databases (canonical name list) per depreview-runtime.
      const stackDbs = asArray(dep.stack && dep.stack.databases);
      if (stackDbs.length) return true;
    } catch (e) { /* fall through to driver scan */ }

    // Fallback: scan dependency manifests / source for a DB driver.
    try {
      let found = false;
      asArray(entries).forEach(e => {
        if (found || !e || !e.path) return;
        const b = basename(e.path).toLowerCase();
        const isManifest = /^(?:package\.json|requirements.*\.txt|pyproject\.toml|composer\.json|cargo\.toml|pom\.xml|build\.gradle|gemfile|go\.mod)$/i.test(b);
        if (!isManifest) return;
        const text = scanText(e);
        if (text == null) return;
        if (DB_DRIVER_RE.test(text)) found = true;
      });
      return found;
    } catch (e) { return false; }
  }

  // ===========================================================================
  // Detectors — each builds part of the operational picture. Defensive: any
  // single entry failure is swallowed.
  // ===========================================================================

  function detect(entries) {
    const out = {
      health: [],          // [label]
      healthEvidence: null, // { file, line, snippet }
      monitoring: [],      // [label]
      monEvidence: null,
      alerting: false, alertEvidence: null,
      backup: false, backupEvidence: null,
      restore: false, restoreEvidence: null,
      dr: false, drEvidence: null
    };

    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        const path = String(e.path);
        const text = scanText(e);
        if (text == null) return;

        // --- Health / readiness: app routes (anchored to a route definition).
        try {
          if (ROUTE_DEF_RE.test(text)) {
            HEALTH_PATHS.forEach(h => {
              try {
                if (h.re.test(text)) {
                  pushUnique(out.health, h.label);
                  if (!out.healthEvidence) {
                    out.healthEvidence = { file: path, line: lineOf(text, h.re), snippet: snippetAt(text, h.re) };
                  }
                }
              } catch (er) { /* skip path */ }
            });
          }
        } catch (er) { /* skip */ }

        // --- Health: Kubernetes probes (yaml manifests).
        try {
          if (isYaml(path)) {
            K8S_PROBE.forEach(p => {
              try {
                if (p.re.test(text)) {
                  pushUnique(out.health, p.label);
                  if (!out.healthEvidence) out.healthEvidence = { file: path, line: lineOf(text, p.re), snippet: snippetAt(text, p.re) };
                }
              } catch (er) { /* skip */ }
            });
          }
        } catch (er) { /* skip */ }

        // --- Health: Dockerfile HEALTHCHECK / compose healthcheck (low conf).
        try {
          if (isDockerfile(path) && DOCKER_HEALTHCHECK_RE.test(text)) {
            pushUnique(out.health, 'docker:HEALTHCHECK');
            if (!out.healthEvidence) out.healthEvidence = { file: path, line: lineOf(text, DOCKER_HEALTHCHECK_RE), snippet: snippetAt(text, DOCKER_HEALTHCHECK_RE) };
          }
          if (isCompose(path) && COMPOSE_HEALTHCHECK_RE.test(text)) {
            pushUnique(out.health, 'compose:healthcheck');
            if (!out.healthEvidence) out.healthEvidence = { file: path, line: lineOf(text, COMPOSE_HEALTHCHECK_RE), snippet: snippetAt(text, COMPOSE_HEALTHCHECK_RE) };
          }
        } catch (er) { /* skip */ }

        // --- Monitoring / observability.
        try {
          MONITORING.forEach(m => {
            try {
              if (m.re.test(text)) {
                pushUnique(out.monitoring, m.label);
                if (!out.monEvidence) out.monEvidence = { file: path, line: lineOf(text, m.re), snippet: snippetAt(text, m.re) };
              }
            } catch (er) { /* skip tool */ }
          });
        } catch (er) { /* skip */ }

        // --- Alerting.
        try {
          if (!out.alerting && ALERTING_RE.test(text)) {
            out.alerting = true;
            out.alertEvidence = { file: path, line: lineOf(text, ALERTING_RE), snippet: snippetAt(text, ALERTING_RE) };
          }
        } catch (er) { /* skip */ }

        // --- Backup (content or path evidence).
        try {
          if (!out.backup) {
            if (BACKUP_PATH_RE.test(path)) {
              out.backup = true;
              out.backupEvidence = { file: path, line: null, snippet: '' };
            } else if (BACKUP_RE.test(text)) {
              out.backup = true;
              out.backupEvidence = { file: path, line: lineOf(text, BACKUP_RE), snippet: snippetAt(text, BACKUP_RE) };
            }
          }
        } catch (er) { /* skip */ }

        // --- Restore (content or path evidence).
        try {
          if (!out.restore) {
            if (RESTORE_PATH_RE.test(path)) {
              out.restore = true;
              out.restoreEvidence = { file: path, line: null, snippet: '' };
            } else if (RESTORE_RE.test(text)) {
              out.restore = true;
              out.restoreEvidence = { file: path, line: lineOf(text, RESTORE_RE), snippet: snippetAt(text, RESTORE_RE) };
            }
          }
        } catch (er) { /* skip */ }

        // --- Disaster recovery / continuity.
        try {
          if (!out.dr && DR_RE.test(text)) {
            out.dr = true;
            out.drEvidence = { file: path, line: lineOf(text, DR_RE), snippet: snippetAt(text, DR_RE) };
          }
        } catch (er) { /* skip */ }
      } catch (err) { /* skip entry */ }
    });

    return out;
  }

  // Short trimmed snippet around the first match of `re`.
  function snippetAt(text, re) {
    try {
      const m = re.exec(text);
      if (!m) return '';
      const start = text.lastIndexOf('\n', m.index) + 1;
      let end = text.indexOf('\n', m.index);
      if (end < 0) end = text.length;
      return text.slice(start, end).trim().slice(0, 160);
    } catch (e) { return ''; }
  }

  // ===========================================================================
  // Finding builder
  // ===========================================================================

  function mkFinding(ruleId, name, severity, confidence, cwe, ev, impact, remediation, effort, likelihood, compliance) {
    return {
      ruleId: 'operations.' + ruleId,
      name: name,
      category: 'config',
      severity: severity,
      confidence: confidence,
      cwe: cwe,
      file: (ev && ev.file) || null,
      line: (ev && ev.line != null) ? ev.line : null,
      snippet: (ev && ev.snippet) || '',
      module: 'operations',
      impact: impact,
      likelihood: likelihood || 'medium',
      remediationEffort: effort || 'medium',
      remediation: remediation,
      references: REFERENCES,
      complianceMappings: compliance || [],
      source: 'ops-review'
    };
  }

  // ===========================================================================
  // Score
  // ===========================================================================

  function clampInt(n) {
    if (isNaN(n)) return 0;
    n = Math.round(n);
    return n < 0 ? 0 : (n > 100 ? 100 : n);
  }

  // Score formula (0..100, higher = better operational readiness):
  //   start at 100, then SUBTRACT a weighted penalty for each MISSING area:
  //     - no health/readiness endpoint or probe :  -18
  //     - no monitoring / observability         :  -15
  //     - no backup strategy                    :  -18 when a DB/persistent
  //                                                store is present, else -6
  //                                                (a stateless app barely needs
  //                                                data backup, so weight low)
  //     - no restore procedure / runbook        :  -8
  //     - no alerting                           :  -8
  //     - no disaster-recovery / continuity ref :  -8
  //   clamp to 0..100. A repo with all six areas present scores 100; a
  //   stateful service missing everything scores max(0, 100-18-15-18-8-8-8)=25.
  function computeScore(d, dbPresent) {
    let score = 100;
    if (!d.health.length) score -= 18;
    if (!d.monitoring.length) score -= 15;
    if (!d.backup) score -= (dbPresent ? 18 : 6);
    if (!d.restore) score -= 8;
    if (!d.alerting) score -= 8;
    if (!d.dr) score -= 8;
    return clampInt(score);
  }

  // ===========================================================================
  // main
  // ===========================================================================

  function analyze(entries, report) {
    const list = asArray(entries);
    const findings = [];
    const summary = {
      healthEndpoints: [], monitoring: [], alerting: false,
      backup: false, restore: false, dr: false, score: 0
    };

    try {
      const d = detect(list);
      const dbPresent = reportHasDatabase(report, list);

      summary.healthEndpoints = d.health.slice();
      summary.monitoring = d.monitoring.slice();
      summary.alerting = d.alerting;
      summary.backup = d.backup;
      summary.restore = d.restore;
      summary.dr = d.dr;

      // Findings for MISSING operational requirements. Heuristic, release-
      // readiness oriented — confidence intentionally LOW/MEDIUM.

      if (!d.health.length) {
        findings.push(mkFinding(
          'no-health-endpoint', 'No health/readiness endpoint or probe detected',
          'medium', 'low', 'CWE-1059', null,
          'Orchestrators and load balancers cannot tell whether the app is alive/ready, so failed or hung instances keep receiving traffic.',
          'Expose a health/readiness endpoint (e.g. /health, /ready) and wire it to liveness/readiness probes or a load-balancer health check.',
          'low', 'medium', C.health
        ));
      }

      if (!d.monitoring.length) {
        findings.push(mkFinding(
          'no-monitoring', 'No monitoring/observability instrumentation detected',
          'medium', 'low', 'CWE-778', null,
          'Without metrics, tracing or error reporting, production faults and performance regressions go unseen.',
          'Add observability (e.g. Prometheus metrics, OpenTelemetry tracing, or an error reporter such as Sentry) and ship signals to a monitoring backend.',
          'medium', 'medium', C.monitoring
        ));
      }

      if (!d.backup) {
        // Only escalate when a database / persistent store is clearly present;
        // otherwise keep it low/info to avoid noise on stateless apps.
        const sev = dbPresent ? 'medium' : 'info';
        const like = dbPresent ? 'medium' : 'low';
        findings.push(mkFinding(
          'no-backup', 'No data backup strategy detected',
          sev, 'low', 'CWE-404', null,
          dbPresent
            ? 'The application uses a persistent datastore but no backup mechanism was found, so data loss may be unrecoverable.'
            : 'No backup mechanism was found; data loss may be unrecoverable if persistent state is later introduced.',
          'Add an automated backup (e.g. pg_dump/mysqldump/mongodump, volume snapshots, or restic/velero) on a schedule with off-site retention.',
          'medium', like, C.backup
        ));
      }

      if (!d.restore) {
        findings.push(mkFinding(
          'no-restore', 'No restore procedure / runbook detected',
          'low', 'low', 'CWE-404', null,
          'Untested or undocumented restores mean backups may not actually be recoverable when needed.',
          'Document and periodically test a restore procedure (e.g. pg_restore/mongorestore) in a runbook.',
          'medium', 'low', C.restore
        ));
      }

      if (!d.alerting) {
        findings.push(mkFinding(
          'no-alerting', 'No alerting / on-call notification detected',
          'low', 'low', 'CWE-778', null,
          'Detected incidents may not reach an operator, delaying response to outages or attacks.',
          'Configure alerting (e.g. Alertmanager, PagerDuty/Opsgenie, or an alert webhook to Slack/Teams) on key health and error signals.',
          'medium', 'low', C.alerting
        ));
      }

      if (!d.dr) {
        findings.push(mkFinding(
          'no-dr', 'No disaster-recovery / continuity reference detected',
          'low', 'low', 'CWE-1059', null,
          'Without a documented DR/continuity plan (failover, RTO/RPO, multi-region), recovery from a major outage is ad hoc.',
          'Document a disaster-recovery / business-continuity plan covering failover, RTO/RPO targets and a recovery runbook.',
          'medium', 'low', C.dr
        ));
      }

      summary.score = computeScore(d, dbPresent);
    } catch (e) {
      // degrade: return whatever was assembled.
    }

    return { findings: findings, summary: summary };
  }

  CITADEL.reviewOperations = { analyze: analyze };
})(window);
