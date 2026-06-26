/* CITADEL — Threat Model Reviewer (STRIDE).
 * Builds a lightweight, generated threat model from DETECTED surfaces — it
 * primarily reuses report.depreview (runtime services/databases/ports/envVars,
 * externalServices, infra) plus report.deployment, report.sbom and
 * report.findings (finding categories tell us where public/auth APIs, secrets,
 * PII and injection points live). It does NOT push into report.findings — the
 * result is a standalone artifact.
 *
 * Pure, offline, defensive: no network, no DOM, no Date. Never throws — every
 * section is wrapped in try/catch and degrades to a safe default. report.* keys
 * may be missing, so everything is guarded.
 *
 * Exports CITADEL.reviewThreatModel.analyze(entries, report) -> {
 *   overview, assets, entryPoints, trustBoundaries, externalDependencies,
 *   roles, dataFlows, threats, summary }
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

  function uniq(list) {
    const seen = {};
    const out = [];
    arr(list).forEach(function (v) {
      if (v == null) return;
      const k = String(v);
      if (seen[k]) return;
      seen[k] = true;
      out.push(v);
    });
    return out;
  }

  // ---------------------------------------------------------------------------
  // Signal extraction — fold report.depreview + report.findings into a compact
  // set of booleans/lists we can reason about.
  // ---------------------------------------------------------------------------

  function depreview(report) {
    return (report && report.depreview) || {};
  }

  function runtime(report) {
    const dep = depreview(report);
    return dep.runtime || {};
  }

  function findingCategories(report) {
    const cats = {};
    arr(report && report.findings).forEach(function (f) {
      if (f && f.category) cats[String(f.category).toLowerCase()] = true;
    });
    return cats;
  }

  function hasCat(cats /* obj */) {
    const names = Array.prototype.slice.call(arguments, 1);
    return names.some(function (n) { return !!cats[n]; });
  }

  // Light fallback scan when depreview is absent — just enough to know whether
  // there ARE routes, uploads, auth and admin surfaces.
  function fallbackScan(entries) {
    const out = { routes: false, auth: false, upload: false, admin: false, ports: false, db: false };
    try {
      textEntries(entries).forEach(function (e) {
        const c = slice(e.content);
        const p = lc(e.path);
        if (!c) return;
        if (/\b(app|router|route)\.(get|post|put|delete|patch)\s*\(|@(Get|Post|Put|Delete|RequestMapping)|@app\.route|@router\.(get|post)/.test(c)) out.routes = true;
        if (/\b(passport|jwt|jsonwebtoken|authenticate|login|session|oauth|bcrypt)\b/i.test(c)) out.auth = true;
        if (/\b(multer|upload|multipart\/form-data|busboy|formidable|FileField|MultipartFile)\b/i.test(c)) out.upload = true;
        if (/\/admin\b|isAdmin|is_admin|role\s*===?\s*['"]admin|requireAdmin/i.test(c) || /admin/.test(p)) out.admin = true;
        if (/\.listen\(\s*\d|EXPOSE\s+\d/i.test(c)) out.ports = true;
        if (/\b(postgres|mysql|mongodb|sqlite|sequelize|prisma|mongoose|\.query\()\b/i.test(c)) out.db = true;
      });
    } catch (e) { /* degrade */ }
    return out;
  }

  function gatherSignals(entries, report) {
    const dep = depreview(report);
    const rt = runtime(report);
    const cats = findingCategories(report);
    const fb = fallbackScan(entries);

    const services = arr(rt.services);
    const databases = arr(rt.databases);
    const ports = arr(rt.ports);
    const envVars = arr(rt.envVars);
    const externalServices = arr(dep.externalServices);
    const infra = arr(dep.infra);
    const deployment = arr(report && report.deployment);
    const sbom = (report && report.sbom) || {};

    const hasDepreview = !!(report && report.depreview);

    const secretEnv = envVars.filter(function (v) { return v && v.secret; });

    return {
      hasDepreview: hasDepreview,
      services: services,
      databases: databases,
      ports: ports,
      envVars: envVars,
      secretEnv: secretEnv,
      externalServices: externalServices,
      infra: infra,
      deployment: deployment,
      sbom: sbom,
      cats: cats,
      fb: fb,
      // Higher-level booleans (depreview-first, fallback second).
      hasDb: databases.length > 0 || fb.db,
      hasPorts: ports.length > 0 || fb.ports,
      hasRoutes: hasCat(cats, 'authz', 'authn', 'injection', 'xss', 'ssrf', 'input-validation') || fb.routes || ports.length > 0,
      hasAuth: hasCat(cats, 'authn', 'authz', 'session') || fb.auth ||
        services.some(function (s) { return s && s.type === 'auth'; }) ||
        externalServices.some(function (s) { return s && s.category === 'identity'; }),
      hasAuthFindings: hasCat(cats, 'authn', 'authz', 'session'),
      hasUpload: fb.upload,
      hasAdmin: fb.admin,
      hasSecrets: hasCat(cats, 'secrets') || secretEnv.length > 0,
      hasSecretFindings: hasCat(cats, 'secrets'),
      hasPii: hasCat(cats, 'privacy'),
      hasInjection: hasCat(cats, 'injection', 'xss', 'ssrf', 'deserialization', 'path-traversal'),
      hasTransport: hasCat(cats, 'transport'),
      hasCrypto: hasCat(cats, 'crypto', 'random'),
      hasConfig: hasCat(cats, 'config'),
      hasThirdParty: externalServices.length > 0,
      hasCiCd: deployment.length > 0 ||
        infra.some(function (i) { return /docker|kubernetes|terraform|helm|cloudformation/.test(String(i && i.type)); }),
      hasIac: infra.some(function (i) { return /terraform|cloudformation|bicep|arm|pulumi|kubernetes|helm|ansible/.test(String(i && i.type)); }),
      hasSecretsManager: infra.some(function (i) { return i && i.type === 'secrets-manager'; })
    };
  }

  // ---------------------------------------------------------------------------
  // Model derivation
  // ---------------------------------------------------------------------------

  function deriveAssets(sig) {
    const assets = [];
    sig.databases.forEach(function (d) {
      if (d && d.engine) assets.push(d.engine + ' database (persisted application data)');
    });
    if (!sig.databases.length && sig.hasDb) assets.push('Application database (persisted data)');
    sig.services.forEach(function (s) {
      if (s && (s.type === 'cache' || s.type === 'search' || s.type === 'object-storage' || s.type === 'message-broker')) {
        assets.push((s.name || s.type) + ' (' + s.type + ' data store)');
      }
    });
    if (sig.hasSecrets) assets.push('Application secrets / credentials (API keys, tokens, DB passwords)');
    if (sig.secretEnv.length) {
      assets.push('Secret environment variables (' + sig.secretEnv.slice(0, 5).map(function (v) { return v.name; }).join(', ') + ')');
    }
    if (sig.hasPii) assets.push('Personally identifiable information (PII) handled in source');
    if (sig.hasAuth) assets.push('User credentials and session/auth tokens');
    if (sig.hasCiCd) assets.push('Build pipeline and deployment artifacts');
    return uniq(assets);
  }

  function deriveEntryPoints(sig) {
    const eps = [];
    const add = function (name, detail) { eps.push({ name: name, detail: detail }); };

    if (sig.hasRoutes) {
      add('Public HTTP API / web endpoints', sig.hasDepreview ? 'Detected from API/route findings and exposed ports' : 'Detected from route handlers in source');
    }
    if (sig.hasAuth) {
      add('Authentication endpoints', sig.hasAuthFindings ? 'Detected from auth-related findings' : 'Detected from auth libraries/sessions');
    }
    if (sig.hasUpload) {
      add('File-upload endpoints', 'Detected from multipart/upload handling in source');
    }
    if (sig.hasAdmin) {
      add('Administrative portal / privileged routes', 'Detected from admin route or role checks');
    }
    sig.ports.forEach(function (p) {
      if (p && p.port && p.direction === 'listen') {
        add('Exposed network port ' + p.port, (p.protocol || 'tcp') + ' — ' + (p.evidence || 'listening socket'));
      }
    });
    if (sig.hasCiCd) {
      const techs = sig.deployment.map(function (d) { return d && d.tech; }).filter(Boolean);
      add('CI/CD deploy path', techs.length ? ('Pipeline: ' + uniq(techs).slice(0, 4).join(', ')) : 'Infrastructure-as-code / pipeline detected');
    }
    return eps;
  }

  function deriveTrustBoundaries(sig) {
    const tb = [];
    if (sig.hasRoutes) tb.push('Browser / client <-> application API');
    if (sig.hasDb) tb.push('Application <-> database');
    if (sig.services.length) tb.push('Application <-> backing services (cache/queue/search/storage)');
    if (sig.hasThirdParty) tb.push('Application <-> third-party / external services');
    if (sig.hasCiCd) tb.push('CI/CD pipeline <-> production environment');
    if (!tb.length) tb.push('Application process <-> external inputs');
    return uniq(tb);
  }

  function deriveExternalDependencies(sig) {
    const ext = [];
    sig.externalServices.forEach(function (s) {
      if (s && s.name) ext.push(s.name + (s.category ? ' (' + s.category + ')' : ''));
    });
    const ecosystems = {};
    arr(sig.sbom.components).forEach(function (c) {
      if (c && c.ecosystem) ecosystems[c.ecosystem] = true;
    });
    Object.keys(ecosystems).forEach(function (eco) {
      ext.push(eco + ' package ecosystem (transitive dependency supply chain)');
    });
    return uniq(ext);
  }

  function deriveRoles(sig) {
    const roles = ['anonymous'];
    if (sig.hasAuth) roles.push('authenticated user');
    if (sig.hasAdmin || sig.cats.authz) roles.push('administrator / privileged');
    return uniq(roles);
  }

  function deriveDataFlows(sig) {
    const flows = [];
    if (sig.hasRoutes) flows.push({ from: 'client', to: 'application API', data: 'request payloads / credentials' });
    if (sig.hasDb) flows.push({ from: 'application API', to: 'database', data: 'queries and persisted records' });
    if (sig.services.some(function (s) { return s && s.type === 'cache'; })) {
      flows.push({ from: 'application API', to: 'cache', data: 'session / cached objects' });
    }
    if (sig.hasThirdParty) {
      const first = sig.externalServices[0];
      flows.push({ from: 'application', to: (first && first.name) || 'third-party service', data: 'integration API calls (may include secrets/PII)' });
    }
    if (sig.hasCiCd) flows.push({ from: 'CI/CD pipeline', to: 'production environment', data: 'build artifacts and deployment config' });
    if (sig.hasPii) flows.push({ from: 'client', to: 'data store', data: 'personally identifiable information (PII)' });
    return flows;
  }

  // ---------------------------------------------------------------------------
  // STRIDE threat generation — each threat is tied to a detected surface, and
  // mitigations are inferred from ACTUAL signals (findings/posture) where we can.
  // ---------------------------------------------------------------------------

  function buildThreats(sig) {
    const threats = [];
    let n = 0;
    const add = function (t) {
      n += 1;
      threats.push({
        id: 'TM-' + (n < 10 ? '0' + n : '' + n),
        stride: t.stride,
        title: t.title,
        surface: t.surface,
        description: t.description,
        existingMitigations: uniq(arr(t.existingMitigations)),
        missingMitigations: uniq(arr(t.missingMitigations)),
        residualRisk: t.residualRisk || 'medium'
      });
    };

    const tlsMitig = sig.hasTransport
      ? 'TLS configuration present but transport-security findings exist — verify enforcement'
      : 'Transport encryption (TLS) assumed at the edge';

    // --- Spoofing: authentication endpoints ---
    if (sig.hasAuth || sig.hasRoutes) {
      add({
        stride: 'Spoofing',
        title: 'Credential / identity spoofing on authentication endpoints',
        surface: 'Authentication endpoints',
        description: 'An attacker attempts to impersonate a legitimate user by guessing, replaying or stuffing credentials against the auth surface.',
        existingMitigations: [
          sig.hasAuth ? 'Authentication mechanism detected (sessions/JWT/auth provider)' : null,
          tlsMitig
        ],
        missingMitigations: [
          sig.hasAuthFindings ? 'Auth-related findings indicate weaknesses to remediate (review authn/session findings)' : null,
          'No evidence of MFA / brute-force lockout',
          'No evidence of credential-stuffing / rate-limit protection on login'
        ],
        residualRisk: sig.hasAuthFindings ? 'high' : 'medium'
      });
    } else {
      add({
        stride: 'Spoofing',
        title: 'Unauthenticated client impersonation',
        surface: 'Public HTTP endpoints',
        description: 'With no authentication layer detected, any client can present itself as any actor when calling the application.',
        existingMitigations: [tlsMitig],
        missingMitigations: ['No authentication mechanism detected on request surfaces'],
        residualRisk: 'high'
      });
    }

    // --- Tampering: untrusted input ---
    add({
      stride: 'Tampering',
      title: 'Tampering with request inputs / parameters',
      surface: sig.hasRoutes ? 'Public HTTP API / web endpoints' : 'Application input surfaces',
      description: 'An attacker modifies request parameters, bodies or headers to inject payloads or alter application behavior (SQLi, XSS, command/path injection).',
      existingMitigations: [
        sig.cats['input-validation'] ? 'Input-validation findings indicate some validation logic exists' : null
      ],
      missingMitigations: [
        sig.hasInjection ? 'Injection-class findings detected (SQLi/XSS/SSRF/path traversal) — input handling is unsafe in places' : 'No evidence of consistent server-side input validation / output encoding',
        'No evidence of a centralized validation/sanitization layer'
      ],
      residualRisk: sig.hasInjection ? 'high' : 'medium'
    });

    // --- Tampering: IaC / pipeline ---
    if (sig.hasIac || sig.hasCiCd) {
      add({
        stride: 'Tampering',
        title: 'Tampering with infrastructure-as-code or build pipeline',
        surface: 'CI/CD deploy path',
        description: 'An attacker who can modify IaC templates or pipeline definitions can alter deployed infrastructure, inject malicious build steps, or weaken security controls.',
        existingMitigations: [
          sig.hasIac ? 'Infrastructure defined as code (reviewable / version-controlled)' : 'Pipeline configuration present'
        ],
        missingMitigations: [
          sig.cats.config ? 'Configuration findings present in IaC — hardening gaps exist' : null,
          'No evidence of pipeline step protection (signed commits, required reviews, OIDC over long-lived secrets)'
        ],
        residualRisk: 'medium'
      });
    }

    // --- Repudiation: audit logging ---
    add({
      stride: 'Repudiation',
      title: 'Actions cannot be attributed (insufficient audit logging)',
      surface: sig.hasAuth ? 'Authenticated / privileged actions' : 'Application actions',
      description: 'Without tamper-resistant audit logging of security-relevant events, users (or attackers) can deny having performed sensitive actions and incidents cannot be reconstructed.',
      existingMitigations: [],
      missingMitigations: [
        'No evidence of centralized, attributable audit logging (user/IP/timestamp) for security events',
        'No evidence of tamper-resistance or log retention controls'
      ],
      residualRisk: 'medium'
    });

    // --- Information Disclosure: secrets / PII / verbose errors ---
    add({
      stride: 'InformationDisclosure',
      title: 'Disclosure of secrets, credentials or sensitive configuration',
      surface: sig.hasSecretFindings ? 'Source / config containing secrets' : 'Secret/config storage',
      description: 'Hardcoded secrets, leaked credentials or exposed configuration allow an attacker to access backend services, databases and third-party accounts.',
      existingMitigations: [
        sig.hasSecretsManager ? 'Secrets-manager usage detected in infrastructure' : null,
        (!sig.hasSecretFindings && sig.secretEnv.length) ? 'Secrets externalized to environment variables' : null
      ],
      missingMitigations: [
        sig.hasSecretFindings ? 'Secret-exposure findings detected — secrets present in code/config (remove and rotate)' : null,
        !sig.hasSecretsManager ? 'No evidence of a managed secret store / rotation' : null
      ],
      residualRisk: sig.hasSecretFindings ? 'high' : 'medium'
    });

    if (sig.hasPii) {
      add({
        stride: 'InformationDisclosure',
        title: 'Exposure of personally identifiable information (PII)',
        surface: 'Endpoints / stores handling PII',
        description: 'PII detected in source is at risk of disclosure through over-broad responses, logging of sensitive fields, or unencrypted storage/transport.',
        existingMitigations: [sig.hasTransport ? null : 'Transport encryption assumed for PII in transit'],
        missingMitigations: [
          'No evidence of field-level encryption / data minimization for PII',
          'No evidence of access controls scoped to PII records'
        ],
        residualRisk: 'high'
      });
    }

    add({
      stride: 'InformationDisclosure',
      title: 'Verbose errors / information leakage in responses',
      surface: sig.hasRoutes ? 'Public HTTP API / web endpoints' : 'Application responses',
      description: 'Detailed stack traces, framework banners or debug output can reveal internal structure, dependency versions and attack surface to an adversary.',
      existingMitigations: [],
      missingMitigations: [
        sig.cats.config ? 'Configuration findings suggest non-production-hardened settings (e.g. debug mode)' : 'No evidence of centralized error handling that suppresses internal detail'
      ],
      residualRisk: 'low'
    });

    // --- Denial of Service: rate limiting / size limits ---
    add({
      stride: 'DenialOfService',
      title: 'Resource exhaustion / no rate limiting on request surfaces',
      surface: sig.hasRoutes ? 'Public HTTP API / web endpoints' : 'Exposed network ports',
      description: 'Without rate limiting and request/payload size limits, an attacker can exhaust CPU, memory, database connections or upstream quotas to degrade or deny service.',
      existingMitigations: [
        sig.infra.some(function (i) { return i && (i.type === 'load-balancer' || i.type === 'reverse-proxy' || i.type === 'nginx'); }) ? 'Reverse proxy / load balancer present (may provide coarse throttling)' : null
      ],
      missingMitigations: [
        'No evidence of application-level rate limiting',
        'No evidence of request body / upload size limits',
        sig.hasUpload ? 'File-upload endpoint without confirmed size/type limits amplifies DoS risk' : null
      ],
      residualRisk: 'medium'
    });

    if (sig.hasThirdParty) {
      add({
        stride: 'DenialOfService',
        title: 'Cascading failure from third-party dependency',
        surface: 'Application <-> third-party services',
        description: 'A slow or unavailable external dependency can exhaust connection pools or threads if calls lack timeouts and circuit breakers, denying service to users.',
        existingMitigations: [],
        missingMitigations: [
          'No evidence of timeouts / retries-with-backoff / circuit breakers around external calls'
        ],
        residualRisk: 'low'
      });
    }

    // --- Elevation of Privilege: authorization ---
    add({
      stride: 'ElevationOfPrivilege',
      title: 'Missing or broken authorization (privilege escalation)',
      surface: sig.hasAdmin ? 'Administrative portal / privileged routes' : 'Privileged application functions',
      description: 'Weak or missing authorization checks let a lower-privileged actor access administrative functions or other users\' data (broken access control / IDOR).',
      existingMitigations: [
        sig.cats.authz ? 'Authorization logic present (authz findings indicate checks exist)' : null
      ],
      missingMitigations: [
        sig.cats.authz ? 'Authorization findings detected — access-control gaps to remediate' : 'No evidence of consistent, centralized authorization enforcement',
        sig.hasAdmin ? 'Admin surface detected — confirm every privileged route enforces role checks' : null
      ],
      residualRisk: sig.cats.authz ? 'high' : 'medium'
    });

    if (sig.hasDb) {
      add({
        stride: 'ElevationOfPrivilege',
        title: 'Database access with excessive privileges',
        surface: 'Application <-> database',
        description: 'If the application connects to the database with broad privileges, a successful injection or app compromise grants the attacker full data-tier control.',
        existingMitigations: [],
        missingMitigations: [
          'No evidence of least-privilege database accounts scoped per service'
        ],
        residualRisk: 'low'
      });
    }

    return threats;
  }

  function summarize(threats) {
    const byStride = {
      Spoofing: 0, Tampering: 0, Repudiation: 0,
      InformationDisclosure: 0, DenialOfService: 0, ElevationOfPrivilege: 0
    };
    arr(threats).forEach(function (t) {
      if (t && byStride[t.stride] != null) byStride[t.stride] += 1;
    });
    return { total: arr(threats).length, byStride: byStride };
  }

  function overviewText(sig) {
    const parts = [];
    parts.push('STRIDE threat model generated from detected surfaces');
    parts.push(sig.hasDepreview ? '(derived primarily from dependency-review runtime/infra signals)' : '(derived from a light scan of source — dependency-review data was unavailable)');
    parts.push('. ');
    parts.push('Surfaces considered: ');
    const s = [];
    if (sig.hasRoutes) s.push('HTTP endpoints');
    if (sig.hasAuth) s.push('authentication');
    if (sig.hasDb) s.push('database');
    if (sig.services.length) s.push('backing services');
    if (sig.hasThirdParty) s.push('third-party integrations');
    if (sig.hasCiCd) s.push('CI/CD');
    parts.push(s.length ? s.join(', ') : 'application inputs');
    parts.push('. Mitigations are inferred from existing findings and posture and may require manual confirmation.');
    return parts.join('');
  }

  // ---------------------------------------------------------------------------
  // ORCHESTRATION
  // ---------------------------------------------------------------------------

  function analyze(entries, report) {
    const empty = {
      overview: 'Threat model could not be generated.',
      assets: [], entryPoints: [], trustBoundaries: [], externalDependencies: [],
      roles: [], dataFlows: [], threats: [],
      summary: {
        total: 0,
        byStride: { Spoofing: 0, Tampering: 0, Repudiation: 0, InformationDisclosure: 0, DenialOfService: 0, ElevationOfPrivilege: 0 }
      }
    };

    try {
      const sig = gatherSignals(entries, report || {});
      const threats = buildThreats(sig);
      return {
        overview: overviewText(sig),
        assets: deriveAssets(sig),
        entryPoints: deriveEntryPoints(sig),
        trustBoundaries: deriveTrustBoundaries(sig),
        externalDependencies: deriveExternalDependencies(sig),
        roles: deriveRoles(sig),
        dataFlows: deriveDataFlows(sig),
        threats: threats,
        summary: summarize(threats)
      };
    } catch (e) {
      return empty;
    }
  }

  CITADEL.reviewThreatModel = { analyze: analyze };
})(window);
