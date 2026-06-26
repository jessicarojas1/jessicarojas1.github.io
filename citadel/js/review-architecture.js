/* CITADEL — Architecture Risk Reviewer.
 * Heuristic (NOT certain) architecture-health review over the repo entries.
 * Detects coarse architectural smells: direct DB access from controllers/routes,
 * missing service / validation / authz / error-handling / logging layers,
 * inconsistent API response shape, no environment separation, and no documented
 * architecture. Findings carry LOW/MEDIUM confidence — they are signals for a
 * reviewer, not assertions. Reuses report.depreview defensively where helpful.
 *
 * Pure, offline, defensive: no network, no DOM, no Date. Never throws — every
 * detector is wrapped in try/catch and degrades to an empty result.
 *
 * Exports CITADEL.reviewArchitecture.analyze(entries, report) -> {
 *   findings, summary:{ observations, maintainability, securityArchitecture, score } }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX_SCAN = 200 * 1024;

  function arr(v) {
    return Array.isArray(v) ? v : [];
  }

  function textEntries(entries) {
    if (!Array.isArray(entries)) return [];
    return entries.filter(function (e) {
      return e && e.content && !e.isBinary && typeof e.content === 'string';
    });
  }

  function slice(content) {
    if (!content) return '';
    return content.length > MAX_SCAN ? content.slice(0, MAX_SCAN) : content;
  }

  function lc(path) {
    return String(path || '').toLowerCase();
  }

  function base(path) {
    return String(path || '').split('/').pop().toLowerCase();
  }

  // Direct DB / ORM access indicators inside arbitrary source.
  const DB_ACCESS = /\.(query|execute|exec)\s*\(|\.raw\s*\(|db\.(query|collection|select|insert|update|delete)\b|knex\s*\(|sequelize\.(query|define)|\.findOne\s*\(|\.findAll\s*\(|prisma\.[a-zA-Z]+\.(find|create|update|delete)|cursor\.execute|session\.query|->query\(|mysqli_query|pg\.(query|Pool)|MongoClient|collection\(/;
  const SQL_LITERAL = /\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b[\s\S]{0,80}\b(FROM|INTO|SET|WHERE|VALUES)\b/i;

  function isControllerOrRoute(path) {
    const p = lc(path);
    const b = base(path);
    return /(^|\/)(controllers?|routes?|handlers?|endpoints?|api|views?|resources?)\//.test(p) ||
      /(controller|route|handler|router|endpoint)s?\.(js|ts|py|rb|php|go|java|cs)$/.test(b);
  }

  // ---------------------------------------------------------------------------
  // Repo structure summary — directory/marker presence across entries.
  // ---------------------------------------------------------------------------

  function structure(entries) {
    const s = {
      hasServices: false, hasValidators: false, hasMiddleware: false,
      hasErrorHandler: false, hasLogger: false, hasAuthzCentral: false,
      hasEnvConfig: false, hasArchDocs: false, hasRepository: false,
      routeFiles: [], controllerFiles: [], totalSourceFiles: 0,
      authzInlineHits: 0, responseShapes: {}, acceptsInput: false
    };
    const text = textEntries(entries);

    text.forEach(function (e) {
      const p = lc(e.path);
      const b = base(e.path);
      const c = slice(e.content);

      if (/\.(js|ts|jsx|tsx|py|rb|php|go|java|cs|mjs|cjs)$/.test(b) && !/\.(test|spec)\./.test(b)) {
        s.totalSourceFiles += 1;
      }

      if (/(^|\/)(services?|service-layer|domain|usecases?|use-cases?|application)\//.test(p) || /\.service\.(js|ts|py|rb|php|go|java|cs)$/.test(b)) s.hasServices = true;
      if (/(^|\/)(validators?|validation|schemas?|dto|dtos)\//.test(p) || /(schema|validator|\.dto)\.(js|ts|py|rb|php|go|java|cs)$/.test(b)) s.hasValidators = true;
      if (/(^|\/)(middlewares?|interceptors?|guards?|filters?)\//.test(p) || /\.(middleware|guard|interceptor)\.(js|ts|py|rb|php|go|java|cs)$/.test(b)) s.hasMiddleware = true;
      if (/(^|\/)(repositor(y|ies)|dao|daos|persistence|models?)\//.test(p)) s.hasRepository = true;

      if (isControllerOrRoute(e.path)) {
        if (/route|router|endpoint/.test(p) || /route/.test(b)) s.routeFiles.push(e);
        else s.controllerFiles.push(e);
      }

      // Environment separation / config.
      if (/\.env(\.|$)|config\/(default|production|development|staging)|appsettings\.(production|development)|settings\/(prod|dev|base)/.test(p) ||
        /NODE_ENV|APP_ENV|FLASK_ENV|RAILS_ENV|ASPNETCORE_ENVIRONMENT/.test(c)) s.hasEnvConfig = true;

      // Documented architecture.
      if (/architecture|adr|\/docs\//.test(p) && /\.(md|rst|adoc|txt)$/.test(b)) s.hasArchDocs = true;
      if (b === 'architecture.md' || /(^|\/)adr(s)?\//.test(p) || /(^|\/)docs\/architecture/.test(p)) s.hasArchDocs = true;

      // Centralized logging.
      if (/(^|\/)(logger|logging)\b/.test(p) || /winston|pino|bunyan|log4j|logback|serilog|zap\.|structlog|logging\.getLogger\(|createLogger\(/.test(c)) s.hasLogger = true;

      // Centralized error handling.
      if (/error.?handler|errorMiddleware|exceptionFilter|@ExceptionHandler|errorhandler|process\.on\(\s*['"]uncaughtException/.test(c) ||
        /\(\s*err\s*,\s*req\s*,\s*res\s*,\s*next\s*\)|app\.use\(\s*function\s*\(\s*err/.test(c) ||
        /(^|\/)(errors?|exceptions?)\//.test(p)) s.hasErrorHandler = true;

      // Centralized authz (middleware/guard/policy) vs inline checks.
      if (/(^|\/)(policies|policy|authorization|authz|abac|rbac)\b/.test(p) ||
        /can\(|authorize\(|@PreAuthorize|@RolesAllowed|requirePermission|requireRole|ensurePermission|Gate::/.test(c)) s.hasAuthzCentral = true;
      const inlineAuthz = c.match(/if\s*\(\s*[^)]*\b(role|isAdmin|is_admin|user\.admin|req\.user\.role|current_user\.role)\b/gi);
      if (inlineAuthz) s.authzInlineHits += inlineAuthz.length;

      // Input acceptance (so "missing validation layer" is meaningful).
      if (/req\.(body|query|params)|request\.(form|json|args|GET|POST)|@RequestBody|ctx\.request\.body|params\.permit|HttpServletRequest/.test(c)) s.acceptsInput = true;

      // Response shape sampling (JSON envelope consistency).
      const m = c.match(/res\.(json|send|status\(\d+\)\.json)\s*\(\s*\{([^}]{0,60})/g) || [];
      m.forEach(function (frag) {
        let key = 'plain';
        if (/success\s*:/.test(frag)) key = 'success-envelope';
        else if (/\berror\s*:/.test(frag)) key = 'error-key';
        else if (/\bdata\s*:/.test(frag)) key = 'data-envelope';
        else if (/\bmessage\s*:/.test(frag)) key = 'message-key';
        s.responseShapes[key] = (s.responseShapes[key] || 0) + 1;
      });
    });

    return s;
  }

  // ---------------------------------------------------------------------------
  // Finding factory
  // ---------------------------------------------------------------------------

  function mkFinding(o) {
    return {
      ruleId: o.ruleId,
      name: o.name,
      category: 'config',
      module: 'architecture',
      severity: o.severity || 'low',
      confidence: o.confidence || 'low',
      cwe: o.cwe || null,
      file: o.file || null,
      line: o.line || null,
      snippet: o.snippet || null,
      impact: o.impact || '',
      likelihood: o.likelihood || 'low',
      remediationEffort: o.remediationEffort || 'medium',
      remediation: o.remediation || '',
      references: arr(o.references),
      complianceMappings: arr(o.complianceMappings),
      source: 'architecture-review'
    };
  }

  const ISO_SECDEV = { framework: 'ISO 27001', control: 'A.14 Secure development', note: 'Potential control weakness — requires compliance owner review' };
  const OWASP_DESIGN = { framework: 'OWASP', control: 'A04:2021 Insecure Design', note: 'Potential control weakness — requires manual confirmation' };

  // ---------------------------------------------------------------------------
  // Detectors — each returns 0..n findings.
  // ---------------------------------------------------------------------------

  function detectDirectDbAccess(s) {
    const out = [];
    const offenders = s.controllerFiles.concat(s.routeFiles).filter(function (e) {
      const c = slice(e.content);
      return DB_ACCESS.test(c) || SQL_LITERAL.test(c);
    });
    if (offenders.length) {
      const sample = offenders[0];
      out.push(mkFinding({
        ruleId: 'arch-direct-db-access',
        name: 'Direct database access from controllers/routes',
        severity: 'medium',
        confidence: 'medium',
        cwe: 'CWE-1061',
        file: sample.path,
        impact: 'Business/persistence logic leaks into the HTTP layer, hurting testability, reuse and consistent access control.',
        likelihood: 'medium',
        remediationEffort: 'medium',
        remediation: 'Introduce a service/repository layer and move data-access calls out of route/controller handlers. (Heuristic finding — confirm by review.)',
        references: ['https://martinfowler.com/eaaCatalog/repository.html'],
        complianceMappings: [ISO_SECDEV, OWASP_DESIGN]
      }));
    }
    return out;
  }

  function detectMissingServiceLayer(s) {
    const out = [];
    const routeCount = s.routeFiles.length + s.controllerFiles.length;
    if (!s.hasServices && !s.hasRepository && routeCount >= 3) {
      out.push(mkFinding({
        ruleId: 'arch-missing-service-layer',
        name: 'No service / domain layer despite multiple route handlers',
        severity: 'low',
        confidence: 'low',
        impact: 'Without a service/domain layer, logic tends to concentrate in controllers, reducing reuse and increasing duplication.',
        likelihood: 'medium',
        remediationEffort: 'high',
        remediation: 'Extract a services/ (or domain/use-case) layer between controllers and persistence. (Low-confidence heuristic — based on directory structure.)',
        complianceMappings: [ISO_SECDEV]
      }));
    }
    return out;
  }

  function detectMissingValidationLayer(s) {
    const out = [];
    if (!s.hasValidators && s.acceptsInput) {
      out.push(mkFinding({
        ruleId: 'arch-missing-validation-layer',
        name: 'No centralized input-validation layer while accepting external input',
        severity: 'medium',
        confidence: 'low',
        cwe: 'CWE-20',
        impact: 'Ad-hoc or absent input validation increases the chance of injection and malformed-data defects.',
        likelihood: 'medium',
        remediationEffort: 'medium',
        remediation: 'Adopt a schema/validation layer (e.g. validators/, DTOs, schema definitions) applied at the request boundary. (Heuristic — no validators/schemas directory detected.)',
        references: ['https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html'],
        complianceMappings: [{ framework: 'OWASP', control: 'Input Validation', note: 'Potential control weakness — requires manual confirmation' }, ISO_SECDEV]
      }));
    }
    return out;
  }

  function detectMissingCentralAuthz(s) {
    const out = [];
    if (!s.hasAuthzCentral && s.authzInlineHits >= 2) {
      out.push(mkFinding({
        ruleId: 'arch-scattered-authz',
        name: 'Authorization checks appear inline/scattered (no central policy layer)',
        severity: 'medium',
        confidence: 'low',
        cwe: 'CWE-285',
        impact: 'Scattered, inline authorization is easy to miss on new routes, leading to broken access control.',
        likelihood: 'medium',
        remediationEffort: 'medium',
        remediation: 'Centralize authorization in middleware/guards/policies and apply uniformly. (Heuristic — counted inline role checks without a policy layer.)',
        references: ['https://owasp.org/Top10/A01_2021-Broken_Access_Control/'],
        complianceMappings: [OWASP_DESIGN, { framework: 'NIST SP 800-171', control: '3.1 Access Control', note: 'Potential control weakness — requires compliance owner review' }]
      }));
    }
    return out;
  }

  function detectMissingErrorHandling(s) {
    const out = [];
    if (!s.hasErrorHandler && (s.routeFiles.length + s.controllerFiles.length) >= 1) {
      out.push(mkFinding({
        ruleId: 'arch-missing-error-handling',
        name: 'No centralized error-handling layer detected',
        severity: 'low',
        confidence: 'low',
        cwe: 'CWE-755',
        impact: 'Inconsistent error handling can leak internal detail in responses and produce unpredictable failure behavior.',
        likelihood: 'low',
        remediationEffort: 'low',
        remediation: 'Add centralized error-handling middleware / a global exception handler that returns safe, consistent responses. (Heuristic.)',
        complianceMappings: [OWASP_DESIGN]
      }));
    }
    return out;
  }

  function detectMissingLogging(s) {
    const out = [];
    if (!s.hasLogger && s.totalSourceFiles >= 5) {
      out.push(mkFinding({
        ruleId: 'arch-missing-central-logging',
        name: 'No centralized logging facility detected',
        severity: 'low',
        confidence: 'low',
        cwe: 'CWE-778',
        impact: 'Without a shared logger, security-relevant events are unlikely to be captured consistently for monitoring/forensics.',
        likelihood: 'medium',
        remediationEffort: 'medium',
        remediation: 'Adopt a structured, centralized logging library and route security events through it. (Heuristic — see logging reviewer for event coverage.)',
        complianceMappings: [{ framework: 'OWASP', control: 'A09:2021 Logging & Monitoring Failures', note: 'Potential control weakness — requires manual confirmation' }]
      }));
    }
    return out;
  }

  function detectInconsistentResponseShape(s) {
    const out = [];
    const shapes = Object.keys(s.responseShapes);
    if (shapes.length >= 3) {
      out.push(mkFinding({
        ruleId: 'arch-inconsistent-response-shape',
        name: 'Inconsistent API response envelope across handlers',
        severity: 'info',
        confidence: 'low',
        impact: 'Mixed response shapes complicate client handling and error parsing, and can mask error states.',
        likelihood: 'low',
        remediationEffort: 'medium',
        remediation: 'Standardize a single response envelope (e.g. { data, error, meta }) via a shared helper/serializer. (Low-confidence heuristic — sampled response shapes: ' + shapes.join(', ') + '.)',
        complianceMappings: [ISO_SECDEV]
      }));
    }
    return out;
  }

  function detectNoEnvSeparation(s) {
    const out = [];
    if (!s.hasEnvConfig && s.totalSourceFiles >= 5) {
      out.push(mkFinding({
        ruleId: 'arch-no-env-separation',
        name: 'No environment separation / configuration detected',
        severity: 'low',
        confidence: 'low',
        cwe: 'CWE-1188',
        impact: 'Without explicit environment config, dev/staging/prod differences risk leaking debug settings or wrong endpoints into production.',
        likelihood: 'low',
        remediationEffort: 'low',
        remediation: 'Introduce per-environment configuration (env files / config profiles) keyed off an environment variable. (Heuristic.)',
        complianceMappings: [ISO_SECDEV]
      }));
    }
    return out;
  }

  function detectNoArchDocs(s) {
    const out = [];
    if (!s.hasArchDocs && s.totalSourceFiles >= 10) {
      out.push(mkFinding({
        ruleId: 'arch-no-documentation',
        name: 'No documented architecture (ARCHITECTURE.md / ADRs / docs)',
        severity: 'info',
        confidence: 'low',
        impact: 'Undocumented architecture slows onboarding and review, and makes security/maintainability assumptions implicit.',
        likelihood: 'low',
        remediationEffort: 'low',
        remediation: 'Add an ARCHITECTURE.md and/or Architecture Decision Records describing components, boundaries and data flows. (Heuristic.)',
        complianceMappings: [ISO_SECDEV]
      }));
    }
    return out;
  }

  // ---------------------------------------------------------------------------
  // Summary + score
  // ---------------------------------------------------------------------------

  function buildSummary(s, findings) {
    const observations = [];
    const maintainability = [];
    const securityArchitecture = [];

    observations.push(s.totalSourceFiles + ' source files; ' + (s.routeFiles.length + s.controllerFiles.length) + ' route/controller files detected.');
    observations.push('Layers present — services: ' + s.hasServices + ', validators: ' + s.hasValidators + ', middleware: ' + s.hasMiddleware + ', repository: ' + s.hasRepository + '.');
    observations.push('Confidence is LOW/MEDIUM: these are heuristic, directory- and pattern-based signals, not verified facts.');

    if (s.hasServices) maintainability.push('Service/domain layer present.');
    else maintainability.push('No clear service/domain layer detected.');
    if (s.hasValidators) maintainability.push('Validation/schema layer present.');
    if (s.hasErrorHandler) maintainability.push('Centralized error handling detected.');
    if (s.hasLogger) maintainability.push('Centralized logging detected.');
    if (Object.keys(s.responseShapes).length) maintainability.push('Sampled API response shapes: ' + Object.keys(s.responseShapes).join(', ') + '.');

    if (s.hasAuthzCentral) securityArchitecture.push('Centralized authorization (policy/guard/middleware) detected.');
    else if (s.authzInlineHits) securityArchitecture.push('Inline/scattered authorization checks detected (' + s.authzInlineHits + ').');
    if (s.hasEnvConfig) securityArchitecture.push('Environment-specific configuration detected.');
    else securityArchitecture.push('No environment separation detected.');

    // Score: start at 100 and deduct weighted penalties per finding severity.
    // Formula: score = clamp(100 - sum(weight[severity]), 0, 100)
    //   medium = 12, low = 6, info = 2. Documents architecture health, not risk.
    const weight = { critical: 25, high: 18, medium: 12, low: 6, info: 2 };
    let penalty = 0;
    arr(findings).forEach(function (f) { penalty += (weight[f.severity] || 6); });
    let score = 100 - penalty;
    if (score < 0) score = 0;
    if (score > 100) score = 100;

    return {
      observations: observations,
      maintainability: maintainability,
      securityArchitecture: securityArchitecture,
      score: score
    };
  }

  // ---------------------------------------------------------------------------
  // ORCHESTRATION
  // ---------------------------------------------------------------------------

  function analyze(entries, report) {
    const empty = {
      findings: [],
      summary: { observations: [], maintainability: [], securityArchitecture: [], score: 100 }
    };
    try {
      // report is reused defensively where helpful; structure scan is the core.
      void report;
      const s = structure(entries);
      let findings = [];
      const detectors = [
        detectDirectDbAccess, detectMissingServiceLayer, detectMissingValidationLayer,
        detectMissingCentralAuthz, detectMissingErrorHandling, detectMissingLogging,
        detectInconsistentResponseShape, detectNoEnvSeparation, detectNoArchDocs
      ];
      detectors.forEach(function (fn) {
        try { findings = findings.concat(fn(s)); } catch (e) { /* skip detector */ }
      });
      return { findings: findings, summary: buildSummary(s, findings) };
    } catch (e) {
      return empty;
    }
  }

  CITADEL.reviewArchitecture = { analyze: analyze };
})(window);
