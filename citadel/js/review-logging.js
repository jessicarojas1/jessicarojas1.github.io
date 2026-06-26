/* CITADEL — Release Readiness Reviewer: Security Event Logging
 * Heuristically determines whether the application logs SECURITY-RELEVANT
 * events (login / failed login / logout / password reset / permission & role
 * changes / admin actions / data export / file upload-download / record CRUD /
 * security exceptions / config changes / sensitive API access) and detects BAD
 * logging practices (secrets/PII/full request bodies logged, missing
 * timestamp/user/IP attribution, no retention, no tamper-resistance).
 *
 * Pure, defensive, offline. Never throws — every detector is wrapped in
 * try/catch and degrades to an empty result. Only text entries (content &&
 * !isBinary) are scanned; very large files are sliced to ~200KB before regex.
 * Mirrors js/depreview-security.js conventions (IIFE on window.CITADEL,
 * curated lists, scoring formula documented in comments).
 *
 * window.CITADEL.reviewLogging
 *   analyze(entries, report)
 *     -> { findings:[Finding], summary:{
 *          eventsCovered:[string], eventsMissing:[string], badPractices:[string],
 *          hasCentralLogger:boolean, score:0..100 } }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX_SCAN_BYTES = 200 * 1024; // slice very large files before regex.

  // ===========================================================================
  // Curated reference lists — intentionally SMALL and ILLUSTRATIVE, tuned for
  // high-signal offline detection. Not exhaustive.
  // ===========================================================================

  // Common logger invocation patterns across ecosystems. A match means the file
  // emits a log somewhere (used to anchor event-keyword proximity scanning).
  // Each is a RegExp source fragment that should precede a '(' or a level call.
  const LOGGER_CALL_RE = new RegExp(
    [
      // JS: winston/pino/bunyan/console + generic logger.<level>(
      '\\b(?:console|logger|log|winston|pino|bunyan)\\s*\\.\\s*(?:log|info|warn|warning|error|debug|trace|fatal|verbose|silly)\\s*\\(',
      // JS: pino()/winston.createLogger()/bunyan.createLogger()/log4js
      '\\b(?:createLogger|getLogger|pino|bunyan|log4js)\\s*\\(',
      // Python logging / loguru
      '\\b(?:logging|logger|log)\\s*\\.\\s*(?:info|warning|warn|error|debug|critical|exception|log)\\s*\\(',
      '\\bloguru\\b',
      // Java log4j / slf4j / logback
      '\\b(?:log|logger|LOG|LOGGER)\\s*\\.\\s*(?:info|warn|error|debug|trace|fatal)\\s*\\(',
      '\\bLoggerFactory\\s*\\.\\s*getLogger\\s*\\(',
      // PHP monolog / PSR-3
      '\\$(?:log|logger|monolog)\\s*->\\s*(?:info|warning|warn|error|debug|critical|notice|alert|emergency|log)\\s*\\(',
      // Go zap / logrus / standard log
      '\\b(?:log|logger|zap|logrus|sugar)\\s*\\.\\s*(?:Print|Printf|Println|Info|Infof|Warn|Warnf|Error|Errorf|Debug|Debugf|Fatal|Fatalf|Sugar)\\s*\\(',
      // .NET Serilog / ILogger
      '\\b(?:Log|_logger|logger|Serilog)\\s*\\.\\s*(?:Information|Warning|Error|Debug|Fatal|Verbose|LogInformation|LogWarning|LogError|LogDebug|LogCritical)\\s*\\('
    ].join('|'),
    'i'
  );

  // Audit-trail / central-logger helper patterns (a dedicated logging or audit
  // module/util that centralizes event recording).
  const AUDIT_HELPER_RE = new RegExp(
    [
      '\\baudit\\s*(?:Log|Trail|Event|Record|Logger)?\\s*[.(]',
      '\\b(?:audit_log|auditLog|writeAudit|recordAudit|logAudit|logEvent|logSecurityEvent|securityLog|trackEvent)\\s*\\(',
      '\\bAuditLogger\\b',
      '\\bSecurityEventLog'
    ].join('|'),
    'i'
  );

  // File-name evidence of a central logger / audit module.
  const LOGGER_FILE_RE = /(?:^|\/|\\)(?:logger|logging|log|audit|auditlog|audit-log|audit_trail|audittrail|winston|pino)\b[^/\\]*\.(?:js|mjs|cjs|ts|py|php|go|java|rb|cs)$/i;

  // Security events we expect a serious app to log. `re` matches event evidence
  // in source/config; we only count it as "covered" when it co-occurs with a
  // logger call in the same file (proximity heuristic).
  const SECURITY_EVENTS = [
    { key: 'login', label: 'User login', re: /\b(?:login|log[ -]?in|sign[ -]?in|signin|authenticate|authentication\s+success)\b/i },
    { key: 'failed-login', label: 'Failed login', re: /\b(?:failed[ _-]?login|login[ _-]?fail(?:ed|ure)?|invalid[ _-]?(?:password|credentials?)|auth(?:entication)?[ _-]?fail(?:ed|ure)?|bad[ _-]?credentials?)\b/i },
    { key: 'logout', label: 'User logout', re: /\b(?:logout|log[ -]?out|sign[ -]?out|signout|session[ _-]?(?:end|terminat))\b/i },
    { key: 'password-reset', label: 'Password reset / change', re: /\b(?:password[ _-]?(?:reset|change|forgot|recover)|reset[ _-]?password|change[ _-]?password|forgot[ _-]?password)\b/i },
    { key: 'permission-change', label: 'Permission / role change', re: /\b(?:permission|role|grant|revoke|privilege|access[ _-]?(?:granted|revoked|control)|assign[ _-]?role)\b/i },
    { key: 'admin-action', label: 'Admin action', re: /\b(?:admin(?:istrator)?|superuser|sudo|root[ _-]?action|elevated|backoffice)\b/i },
    { key: 'data-export', label: 'Data export', re: /\b(?:export(?:ed|ing)?|download[ _-]?(?:report|data|csv|export)|bulk[ _-]?export|extract(?:ed)?[ _-]?data|generate[ _-]?report)\b/i },
    { key: 'file-upload', label: 'File upload', re: /\b(?:upload(?:ed|ing)?|multipart|file[ _-]?upload|attachment[ _-]?(?:added|saved))\b/i },
    { key: 'file-download', label: 'File download', re: /\b(?:download(?:ed|ing)?|file[ _-]?download|serve[ _-]?file|sendfile|download[ _-]?file)\b/i },
    { key: 'record-create', label: 'Record create', re: /\b(?:created?|insert(?:ed)?|added?|new[ _-]?record|registration|register(?:ed)?)\b/i },
    { key: 'record-update', label: 'Record update', re: /\b(?:updated?|modif(?:y|ied)|edit(?:ed)?|changed?|patch(?:ed)?)\b/i },
    { key: 'record-delete', label: 'Record delete', re: /\b(?:deleted?|remov(?:e|ed)|destroy(?:ed)?|purge[ds]?|soft[ _-]?delete)\b/i },
    { key: 'security-exception', label: 'Security exception / error', re: /\b(?:security[ _-]?(?:exception|error|violation|alert)|access[ _-]?denied|forbidden|unauthorized|403|401|csrf|xss|injection|rate[ _-]?limit)\b/i },
    { key: 'config-change', label: 'Configuration change', re: /\b(?:config(?:uration)?[ _-]?(?:change|update|set|modif)|setting[ _-]?(?:change|update)|feature[ _-]?flag|toggle[ _-]?setting)\b/i },
    { key: 'sensitive-api', label: 'Sensitive API access', re: /\b(?:api[ _-]?(?:key|token|access)|sensitive[ _-]?(?:data|endpoint|api)|payment|billing|ssn|pii|phi|financial)\b/i }
  ];

  // Secrets / sensitive identifiers that must never be logged.
  const SECRET_TOKEN_RE = /\b(?:password|passwd|pwd|secret|token|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|bearer|private[_-]?key|client[_-]?secret|credential|session[_-]?id|jwt|auth[_-]?header|cvv|ssn|credit[_-]?card)\b/i;

  // PII identifiers (full PII in logs).
  const PII_RE = /\b(?:ssn|social[_-]?security|credit[_-]?card|card[_-]?number|cvv|date[_-]?of[_-]?birth|dob|passport|driver[_-]?licen[sc]e|tax[_-]?id|bank[_-]?account|routing[_-]?number|phi|medical[_-]?record)\b/i;

  // Full request/response body logged (e.g. log(req.body), log(request.body)).
  const BODY_LOG_RE = /(?:req(?:uest)?|res(?:ponse)?)\s*\.\s*(?:body|payload|data|params|query|headers)\b/i;

  // Retention / tamper-resistance references (presence => good).
  const RETENTION_RE = /\b(?:retention|retain[ _-]?(?:logs?|for)|log[ _-]?rotat|logrotate|max[ _-]?age|maxFiles|maxsize|expire[ _-]?logs?|archive[ _-]?logs?|ttl)\b/i;
  const TAMPER_RE = /\b(?:tamper|append[ _-]?only|immutable|wo?rm\b|integrity[ _-]?(?:hash|check)|hash[ _-]?chain|signed[ _-]?logs?|hmac[ _-]?log|cloudtrail|siem|splunk|datadog|elk\b|elasticsearch|graylog|sumo[ _-]?logic|loki\b)\b/i;

  const COMPLIANCE = [
    { framework: 'NIST SP 800-171', control: '3.3.1', note: 'Create and retain audit records of security-relevant events.' },
    { framework: 'NIST SP 800-171', control: '3.3.2', note: 'Ensure actions are traceable to individual users for accountability.' },
    { framework: 'CMMC L2', control: 'AU.L2-3.3.1', note: 'Mapped control impact: audit logging of system events.' },
    { framework: 'ISO 27001', control: 'A.8.15', note: 'Logging — events should be produced, kept and reviewed.' },
    { framework: 'OWASP', control: 'A09:2021', note: 'Security Logging and Monitoring Failures.' }
  ];

  const REFERENCES = [
    'https://owasp.org/Top10/A09_2021-Security_Logging_and_Monitoring_Failures/',
    'https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html',
    'https://csrc.nist.gov/pubs/sp/800/171/r2/final'
  ];

  // ===========================================================================
  // Helpers
  // ===========================================================================

  function asArray(v) { return Array.isArray(v) ? v : []; }
  function lc(s) { return String(s == null ? '' : s).toLowerCase(); }

  function scanText(e) {
    // Return scannable text for an entry, or null if not a text entry.
    if (!e || typeof e.content !== 'string' || e.content === '') return null;
    if (e.isBinary) return null;
    const c = e.content;
    return c.length > MAX_SCAN_BYTES ? c.slice(0, MAX_SCAN_BYTES) : c;
  }

  function isCodeLike(path) {
    return /\.(?:js|mjs|cjs|jsx|ts|tsx|py|php|go|java|rb|cs|kt|scala|rs|conf|yml|yaml|json|xml|properties|ini|env|toml)$/i.test(String(path || '')) ||
      /(?:^|\/)\.?env/i.test(String(path || ''));
  }

  // Find the 1-based line number of the first match of `re` in `text`.
  function lineOf(text, re) {
    try {
      const m = re.exec(text);
      if (!m) return null;
      const upto = text.slice(0, m.index);
      return upto.split('\n').length;
    } catch (e) { return null; }
  }

  // ===========================================================================
  // Detectors
  // ===========================================================================

  // Detect logger usage + central logger across the codebase. Records, per
  // entry, whether it contains a logger call so event-proximity can be checked.
  function detectLoggers(entries) {
    const out = { hasLogger: false, hasCentralLogger: false, loggerFiles: [], scanned: [] };
    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        const text = scanText(e);
        if (text == null) return;
        if (!isCodeLike(e.path)) return;
        const hasCall = LOGGER_CALL_RE.test(text);
        const hasAudit = AUDIT_HELPER_RE.test(text);
        const fileIsLogger = LOGGER_FILE_RE.test(String(e.path));
        if (hasCall || hasAudit) out.hasLogger = true;
        if (hasAudit || (fileIsLogger && hasCall)) {
          out.hasCentralLogger = true;
          if (out.loggerFiles.indexOf(e.path) < 0) out.loggerFiles.push(e.path);
        } else if (fileIsLogger) {
          if (out.loggerFiles.indexOf(e.path) < 0) out.loggerFiles.push(e.path);
          out.hasCentralLogger = true;
        }
        out.scanned.push({ path: e.path, text: text, hasCall: hasCall || hasAudit });
      } catch (err) { /* skip entry */ }
    });
    return out;
  }

  // Detect which security events appear to be logged. An event is "covered"
  // when its keyword co-occurs with a logger call in the SAME file (proximity
  // heuristic — confidence medium, this is best-effort).
  function detectEventCoverage(scanned) {
    const covered = {};      // key -> { file, line }
    asArray(scanned).forEach(s => {
      if (!s || !s.hasCall) return;
      SECURITY_EVENTS.forEach(ev => {
        try {
          if (covered[ev.key]) return;
          if (ev.re.test(s.text)) {
            covered[ev.key] = { file: s.path, line: lineOf(s.text, ev.re) };
          }
        } catch (e) { /* skip event */ }
      });
    });
    return covered;
  }

  // Detect bad logging practices. Returns a list of { id, label, severity,
  // confidence, file, line, evidence }.
  function detectBadPractices(entries, loggers) {
    const out = [];
    let sawRetention = false;
    let sawTamper = false;

    asArray(entries).forEach(e => {
      try {
        if (!e || !e.path) return;
        const text = scanText(e);
        if (text == null) return;
        if (!isCodeLike(e.path)) return;

        if (RETENTION_RE.test(text)) sawRetention = true;
        if (TAMPER_RE.test(text)) sawTamper = true;

        // Examine each line that contains a logger call for secrets/PII/body.
        const lines = text.split('\n');
        for (let i = 0; i < lines.length; i++) {
          const line = lines[i];
          let isLogLine = false;
          try { isLogLine = LOGGER_CALL_RE.test(line) || AUDIT_HELPER_RE.test(line); } catch (er) { isLogLine = false; }
          if (!isLogLine) continue;

          if (BODY_LOG_RE.test(line)) {
            out.push({
              id: 'request-body-logged', label: 'Full request/response body logged',
              severity: 'high', confidence: 'high',
              file: e.path, line: i + 1, evidence: line.trim().slice(0, 160)
            });
          }
          if (SECRET_TOKEN_RE.test(line)) {
            out.push({
              id: 'secrets-logged', label: 'Secret/token/password referenced in a log statement',
              severity: 'high', confidence: 'medium',
              file: e.path, line: i + 1, evidence: line.trim().slice(0, 160)
            });
          }
          if (PII_RE.test(line)) {
            out.push({
              id: 'pii-logged', label: 'Full PII referenced in a log statement',
              severity: 'high', confidence: 'medium',
              file: e.path, line: i + 1, evidence: line.trim().slice(0, 160)
            });
          }
        }
      } catch (err) { /* skip entry */ }
    });

    // Missing attribution: app logs but no central logger AND no evidence of
    // user/IP/timestamp fields anywhere near log calls. Heuristic, low/medium.
    if (loggers.hasLogger && !loggers.hasCentralLogger) {
      let sawAttribution = false;
      asArray(loggers.scanned).forEach(s => {
        if (!s || !s.hasCall) return;
        try {
          if (/\b(?:userId|user_id|username|req\.ip|remoteAddr|remote_addr|x-forwarded-for|client_?ip|timestamp|@timestamp|created_at|requestId|trace_?id|correlationId)\b/i.test(s.text)) {
            sawAttribution = true;
          }
        } catch (e) { /* skip */ }
      });
      if (!sawAttribution) {
        out.push({
          id: 'missing-attribution', label: 'Log statements lack user/IP/timestamp attribution',
          severity: 'medium', confidence: 'low',
          file: (loggers.scanned[0] && loggers.scanned[0].path) || null, line: null, evidence: ''
        });
      }
    }

    // No retention strategy referenced anywhere.
    if (loggers.hasLogger && !sawRetention) {
      out.push({
        id: 'no-retention', label: 'No log retention / rotation strategy referenced',
        severity: 'medium', confidence: 'low', file: null, line: null, evidence: ''
      });
    }
    // No tamper-resistance / centralized log shipping referenced.
    if (loggers.hasLogger && !sawTamper) {
      out.push({
        id: 'no-tamper-resistance', label: 'No tamper-resistant / centralized log store referenced',
        severity: 'medium', confidence: 'low', file: null, line: null, evidence: ''
      });
    }

    // Dedupe bad practices by id+file+line.
    const seen = {};
    return out.filter(p => {
      const k = p.id + '|' + (p.file || '') + '|' + (p.line || '');
      if (seen[k]) return false;
      seen[k] = 1;
      return true;
    });
  }

  // ===========================================================================
  // Finding builders
  // ===========================================================================

  function badPracticeRemediation(id) {
    switch (id) {
      case 'request-body-logged':
        return 'Do not log raw request/response bodies; log a redacted/allowlisted subset of fields only.';
      case 'secrets-logged':
        return 'Remove secrets/tokens/passwords from log output; redact sensitive fields before logging.';
      case 'pii-logged':
        return 'Mask or hash PII (SSN, card numbers, DOB) before logging; log only minimal identifiers.';
      case 'missing-attribution':
        return 'Include timestamp, authenticated user/subject, and source IP in every security log record.';
      case 'no-retention':
        return 'Define a log retention/rotation policy aligned to compliance requirements.';
      case 'no-tamper-resistance':
        return 'Ship logs to a centralized, append-only/immutable store (SIEM) to resist tampering.';
      default:
        return 'Review the logging practice and align with secure logging guidance.';
    }
  }

  function badPracticeImpact(id) {
    switch (id) {
      case 'request-body-logged':
        return 'Sensitive fields inside request bodies (credentials, PII) leak into log storage.';
      case 'secrets-logged':
        return 'Credentials/tokens in logs can be harvested for account takeover or lateral movement.';
      case 'pii-logged':
        return 'PII in logs expands the data-breach blast radius and violates privacy controls.';
      case 'missing-attribution':
        return 'Without who/when/where, logs cannot support incident investigation or non-repudiation.';
      case 'no-retention':
        return 'Logs may be unavailable for the window required by investigations/audits.';
      case 'no-tamper-resistance':
        return 'Attackers can delete or alter local logs to hide their activity.';
      default:
        return 'Weak logging reduces detection and forensic capability.';
    }
  }

  function badPracticeFinding(p) {
    const isLeak = p.id === 'secrets-logged' || p.id === 'pii-logged' || p.id === 'request-body-logged';
    return {
      ruleId: 'logging.' + p.id,
      name: p.label,
      category: 'logging',
      severity: p.severity,
      confidence: p.confidence,
      cwe: isLeak ? 'CWE-532' : 'CWE-778',
      file: p.file,
      line: p.line,
      snippet: p.evidence || '',
      module: 'logging',
      impact: badPracticeImpact(p.id),
      likelihood: isLeak ? 'medium' : 'medium',
      remediationEffort: isLeak ? 'low' : 'medium',
      remediation: badPracticeRemediation(p.id),
      references: REFERENCES,
      complianceMappings: COMPLIANCE,
      source: 'review-logging'
    };
  }

  function missingCoverageFinding(missingLabels) {
    return {
      ruleId: 'logging.missing-event-coverage',
      name: 'Security events are not consistently logged',
      category: 'logging',
      severity: 'medium',
      confidence: 'medium',
      cwe: 'CWE-778',
      file: null,
      line: null,
      snippet: 'Missing: ' + missingLabels.join(', '),
      module: 'logging',
      impact: 'Important security events go unrecorded, undermining detection, alerting and audit evidence.',
      likelihood: 'medium',
      remediationEffort: 'medium',
      remediation: 'Add audit logging for the uncovered security events: ' + missingLabels.join(', ') + '.',
      references: REFERENCES,
      complianceMappings: COMPLIANCE,
      source: 'review-logging'
    };
  }

  function noLoggingFinding() {
    return {
      ruleId: 'logging.no-security-logging',
      name: 'No security event logging detected',
      category: 'logging',
      severity: 'high',
      confidence: 'medium',
      cwe: 'CWE-778',
      file: null,
      line: null,
      snippet: '',
      module: 'logging',
      impact: 'Security-relevant actions are not recorded, leaving no audit trail for detection or investigation.',
      likelihood: 'high',
      remediationEffort: 'medium',
      remediation: 'Introduce a central audit logger and record authentication, authorization, admin and data-access events.',
      references: REFERENCES,
      complianceMappings: COMPLIANCE,
      source: 'review-logging'
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

  // Score formula (0..100, higher = better logging posture):
  //   base coverage = (eventsCovered / totalEvents) * 70   // up to 70 pts
  //   + central logger bonus               +15 if hasCentralLogger else 0
  //   + retention/tamper hygiene credit    handled via bad-practice penalties
  //   - bad practice penalties:
  //       secrets-logged / pii-logged / request-body-logged : -18 each
  //       missing-attribution                                : -10
  //       no-retention                                       : -6
  //       no-tamper-resistance                               : -6
  //   If NO logger at all -> score is min(score, 10) (effectively near zero,
  //   coverage will already be 0 so this caps the result low).
  // The remaining 15 pts headroom (70 coverage + 15 central = 85) is reserved
  // so a clean app with full coverage, a central logger and no bad practices
  // lands at 85; the final +15 is awarded when there are zero bad practices.
  function computeScore(coveredCount, totalEvents, hasCentralLogger, bad, hasLogger) {
    let score = totalEvents ? (coveredCount / totalEvents) * 70 : 0;
    if (hasCentralLogger) score += 15;
    let penalty = 0;
    asArray(bad).forEach(p => {
      if (p.id === 'secrets-logged' || p.id === 'pii-logged' || p.id === 'request-body-logged') penalty += 18;
      else if (p.id === 'missing-attribution') penalty += 10;
      else if (p.id === 'no-retention') penalty += 6;
      else if (p.id === 'no-tamper-resistance') penalty += 6;
    });
    if (penalty === 0 && hasLogger) score += 15; // clean-logging bonus.
    score -= penalty;
    if (!hasLogger) score = Math.min(score, 10);
    return clampInt(score);
  }

  // ===========================================================================
  // main
  // ===========================================================================

  function analyze(entries, report) {
    const list = asArray(entries);
    const findings = [];
    const summary = {
      eventsCovered: [], eventsMissing: [], badPractices: [],
      hasCentralLogger: false, score: 0
    };

    try {
      const loggers = detectLoggers(list);
      summary.hasCentralLogger = loggers.hasCentralLogger;

      const coverage = detectEventCoverage(loggers.scanned);
      const coveredKeys = {};
      SECURITY_EVENTS.forEach(ev => {
        if (coverage[ev.key]) {
          summary.eventsCovered.push(ev.key);
          coveredKeys[ev.key] = 1;
        } else {
          summary.eventsMissing.push(ev.key);
        }
      });

      const bad = detectBadPractices(list, loggers);
      summary.badPractices = bad.map(p => p.id);

      // Findings: bad practices.
      bad.forEach(p => { try { findings.push(badPracticeFinding(p)); } catch (e) { /* skip */ } });

      // Findings: coverage.
      if (!loggers.hasLogger) {
        findings.push(noLoggingFinding());
      } else if (summary.eventsMissing.length) {
        const missingLabels = SECURITY_EVENTS
          .filter(ev => !coveredKeys[ev.key])
          .map(ev => ev.label);
        // Only flag if a meaningful chunk is missing (heuristic, medium conf).
        if (missingLabels.length) findings.push(missingCoverageFinding(missingLabels));
      }

      summary.score = computeScore(
        summary.eventsCovered.length, SECURITY_EVENTS.length,
        loggers.hasCentralLogger, bad, loggers.hasLogger
      );
    } catch (e) {
      // degrade: return whatever was assembled.
    }

    return { findings: findings, summary: summary };
  }

  CITADEL.reviewLogging = { analyze: analyze };
})(window);
