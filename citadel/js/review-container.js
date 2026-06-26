/* CITADEL — Release Readiness Reviewer: Container & Orchestration Hardening
 * Deep checks for DOCKERFILE and DOCKER-COMPOSE files (plus best-effort
 * Kubernetes manifests) that the line-regex rules in js/rules-iac.js do NOT
 * cover. rules-iac already ships some K8s container rules
 * (k8s-readonly-rootfs-false, k8s-cap-sysadmin, k8s-hostpath, k8s-automount-sa,
 * docker-sudo); this module focuses on multi-line / stateful Dockerfile and
 * Compose analysis (root user across the whole file, base-image pinning,
 * baked-in secrets, remote-exec, cache hygiene, healthchecks, dangerous Compose
 * keys, docker.sock mounts, exposed management ports) and a consolidated
 * container summary. Light K8s overlap is fine — findings are de-duped
 * downstream by fingerprint.
 *
 * Pure, defensive, offline. Never throws — every detector is wrapped in
 * try/catch and degrades to an empty result. No network/DOM/Date. Only text
 * entries (content && !isBinary) are scanned; very large files are sliced to
 * ~200KB before parsing. Worker-safe IIFE on window.CITADEL.reviewContainer.
 *
 * window.CITADEL.reviewContainer
 *   analyze(entries)
 *     -> { findings:[Finding], summary:{
 *          dockerfiles:int, composeFiles:int, k8sFiles:int,
 *          images:[{ ref, pinned, latest }],
 *          runsAsRoot:boolean, privileged:boolean, hostNetwork:boolean,
 *          score:0..100 } }
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const MAX_SCAN_BYTES = 200 * 1024; // slice very large files before parsing.

  // ===========================================================================
  // Compliance / reference helpers — phrasing is intentionally non-certain
  // ("Mapped control impact", "relevant to") since detection is heuristic.
  // ===========================================================================

  const REFERENCES = [
    'https://docs.docker.com/develop/develop-images/dockerfile_best-practices/',
    'https://www.cisecurity.org/benchmark/docker',
    'https://www.cisecurity.org/benchmark/kubernetes'
  ];

  function cis(control, note) {
    return { framework: 'CIS Docker Benchmark', control: control, note: note };
  }
  function cisK8s(control, note) {
    return { framework: 'CIS Kubernetes Benchmark', control: control, note: note };
  }
  function nist(control, note) {
    return { framework: 'NIST SP 800-53', control: control, note: note };
  }

  // ===========================================================================
  // Generic helpers
  // ===========================================================================

  function asArray(v) { return Array.isArray(v) ? v : []; }

  function scanText(e) {
    if (!e || typeof e.content !== 'string' || e.content === '') return null;
    if (e.isBinary) return null;
    const c = e.content;
    return c.length > MAX_SCAN_BYTES ? c.slice(0, MAX_SCAN_BYTES) : c;
  }

  // Basename of a path, tolerating archive markers like "zip!/a/b/Dockerfile".
  function basename(path) {
    let p = String(path == null ? '' : path);
    const bang = p.lastIndexOf('!');
    if (bang >= 0) p = p.slice(bang + 1);
    p = p.replace(/\\/g, '/');
    const slash = p.lastIndexOf('/');
    if (slash >= 0) p = p.slice(slash + 1);
    return p;
  }

  function isDockerfile(path) {
    const b = basename(path).toLowerCase();
    return b === 'dockerfile' || b.indexOf('dockerfile.') === 0 ||
      /\.dockerfile$/.test(b) || /\.dockerfile\./.test(b);
  }

  function isComposeFile(path) {
    const b = basename(path).toLowerCase();
    return b === 'docker-compose.yml' || b === 'docker-compose.yaml' ||
      b === 'compose.yml' || b === 'compose.yaml' ||
      /^docker-compose\..*\.ya?ml$/.test(b) || /^compose\..*\.ya?ml$/.test(b);
  }

  function isYaml(path) {
    return /\.ya?ml$/i.test(basename(path));
  }

  // Best-effort: a YAML file is a K8s manifest if it has `kind:` and looks like
  // it carries a container spec (containers:/image:/spec:).
  function looksLikeK8s(text) {
    try {
      if (!/^\s*kind\s*:/im.test(text)) return false;
      if (!/^\s*(?:apiVersion)\s*:/im.test(text)) return false;
      return /(?:^|\n)\s*-?\s*(?:containers|image|template)\s*:/i.test(text) ||
        /^\s*spec\s*:/im.test(text);
    } catch (e) { return false; }
  }

  // Split a string into trimmed-but-indexed lines so we can compute line numbers.
  function lines(text) {
    return String(text == null ? '' : text).split('\n');
  }

  // ===========================================================================
  // Dockerfile parsing — handle line continuations (`\`) so a multi-line RUN
  // is treated as a single logical instruction, while still tracking the
  // starting line number for findings.
  // ===========================================================================

  function parseDockerfile(text) {
    const raw = lines(text);
    const insns = []; // { kind, args, line }
    let i = 0;
    while (i < raw.length) {
      let line = raw[i];
      const startLine = i + 1;
      // Skip blank / comment lines.
      const trimmed = line.replace(/^\s+/, '');
      if (trimmed === '' || trimmed.charAt(0) === '#') { i++; continue; }
      // Join continuation lines.
      let joined = line;
      while (/\\\s*$/.test(joined) && i + 1 < raw.length) {
        joined = joined.replace(/\\\s*$/, ' ');
        i++;
        joined += raw[i];
      }
      i++;
      const m = /^\s*([A-Za-z]+)\s+([\s\S]*)$/.exec(joined);
      if (!m) continue;
      insns.push({ kind: m[1].toUpperCase(), args: m[2].trim(), line: startLine });
    }
    return insns;
  }

  // Parse a FROM instruction into { ref, pinned, latest, stageAlias, fromStage }.
  // Returns null for stage-reference FROMs that point at a previous build stage.
  function parseFrom(args, knownStages) {
    // FROM [--platform=...] image[:tag][@digest] [AS stage]
    let s = args.replace(/^--\S+\s+/g, '').trim();
    const asMatch = /\s+AS\s+(\S+)\s*$/i.exec(s);
    let stageAlias = null;
    if (asMatch) {
      stageAlias = asMatch[1];
      s = s.slice(0, asMatch.index).trim();
    }
    const ref = s.split(/\s+/)[0] || '';
    if (!ref) return null;
    // FROM <previousStage> — reference to an earlier stage, not a real image.
    if (knownStages && knownStages[ref.toLowerCase()]) {
      return { ref: ref, fromStage: true, stageAlias: stageAlias };
    }
    const hasDigest = /@sha256:[0-9a-f]{8,}/i.test(ref);
    // Determine tag: portion after the LAST ':' that is not inside a digest and
    // not part of a registry:port (registry:port has a '/' after it).
    let tag = null;
    const digestSplit = ref.split('@')[0];
    const lastColon = digestSplit.lastIndexOf(':');
    if (lastColon >= 0) {
      const afterColon = digestSplit.slice(lastColon + 1);
      // registry:port/image has a '/' after the colon -> not a tag.
      if (afterColon.indexOf('/') < 0) tag = afterColon;
    }
    const latest = (!tag && !hasDigest) || (tag != null && tag.toLowerCase() === 'latest');
    const pinned = hasDigest || (tag != null && tag.toLowerCase() !== 'latest');
    return {
      ref: ref, pinned: pinned, latest: latest, hasDigest: hasDigest,
      tag: tag, stageAlias: stageAlias, fromStage: false
    };
  }

  // ===========================================================================
  // Detection regexes
  // ===========================================================================

  // COPY/ADD source that bakes secrets into the image.
  const SECRET_FILE_RE = /(?:^|[\s/"'])(?:\.env(?:\.[\w-]+)?|[\w./-]*\.pem|id_rsa(?:\.pub)?|[\w./-]*\.key|[\w./-]*\.p12|[\w./-]*\.pfx|credentials|\.aws|\.npmrc|\.git|[\w./-]*\.kdbx|service[-_]?account[\w-]*\.json)\b/i;
  // ENV/ARG name that implies a secret.
  const SECRET_NAME_RE = /\b([A-Z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|APIKEY|API_KEY|ACCESS_KEY|PRIVATE_KEY|CLIENT_SECRET)[A-Z0-9_]*)\b/i;
  // Download-and-execute pipeline in a RUN.
  const REMOTE_EXEC_RE = /\b(?:curl|wget)\b[^\n|]*\|\s*(?:sudo\s+)?(?:sh|bash|zsh|python\d?)\b/i;
  // SSH server install.
  const SSH_INSTALL_RE = /\b(?:openssh-server|openssh\.server|sshd)\b/i;
  // sudo.
  const SUDO_RE = /\bsudo\b/i;
  // ADD with a remote URL.
  const ADD_URL_RE = /^https?:\/\//i;

  // ===========================================================================
  // Finding builder
  // ===========================================================================

  function finding(o) {
    return {
      ruleId: o.ruleId,
      name: o.name,
      category: 'config',
      severity: o.severity,
      confidence: o.confidence,
      cwe: o.cwe || null,
      file: o.file == null ? null : o.file,
      line: o.line == null ? null : o.line,
      snippet: o.snippet == null ? '' : String(o.snippet).slice(0, 200),
      remediation: o.remediation,
      source: 'container-review',
      module: 'container',
      impact: o.impact,
      likelihood: o.likelihood,
      remediationEffort: o.remediationEffort,
      references: REFERENCES.slice(),
      complianceMappings: asArray(o.complianceMappings)
    };
  }

  // ===========================================================================
  // Dockerfile analyzer
  // ===========================================================================

  function analyzeDockerfile(entry, text, out, summary) {
    const insns = parseDockerfile(text);
    const stages = {}; // stageAlias(lc) -> true
    let lastUser = null;      // last effective USER value
    let lastUserLine = null;
    let sawUser = false;
    let sawExplicitRoot = false;
    let explicitRootLine = null;
    let hasHealthcheck = false;
    let hasService = false;   // CMD or ENTRYPOINT present
    let aptInstall = false;
    let aptCleaned = false;
    let apkInstall = false;
    let apkNoCache = false;
    let yumInstall = false;
    let yumCleaned = false;

    insns.forEach(ins => {
      try {
        const kind = ins.kind;
        const args = ins.args;
        if (kind === 'FROM') {
          const f = parseFrom(args, stages);
          if (!f) return;
          if (f.stageAlias) stages[f.stageAlias.toLowerCase()] = true;
          if (f.fromStage) return; // reference to a previous stage, not an image
          // Multi-stage: a new FROM resets the effective USER for that stage.
          sawUser = false;
          lastUser = null;
          summary.images.push({ ref: f.ref, pinned: !!f.pinned, latest: !!f.latest });
          if (f.latest) {
            out.push(finding({
              ruleId: 'container.docker-base-image-latest',
              name: f.tag ? 'Base image pinned to :latest' : 'Base image has no tag (implicit latest)',
              severity: 'medium', confidence: 'high', cwe: 'CWE-1104',
              file: entry.path, line: ins.line, snippet: 'FROM ' + f.ref,
              impact: 'Builds are non-reproducible; a moving "latest" tag can silently pull a vulnerable or backdoored image.',
              likelihood: 'medium', remediationEffort: 'low',
              remediation: 'Pin the base image to a specific version and ideally a @sha256 digest (e.g. node:20.11-alpine@sha256:...).',
              complianceMappings: [
                cis('4.4', 'Mapped control impact: rebuild images to include security patches via pinned, current tags.'),
                nist('CM-2', 'Baseline configuration: relevant to deterministic, pinned base images.')
              ]
            }));
          } else if (f.pinned && !f.hasDigest) {
            out.push(finding({
              ruleId: 'container.docker-base-image-no-digest',
              name: 'Base image tag is not pinned to a digest',
              severity: 'info', confidence: 'medium', cwe: 'CWE-1104',
              file: entry.path, line: ins.line, snippet: 'FROM ' + f.ref,
              impact: 'A version tag can be re-pointed by the publisher; only a @sha256 digest guarantees the exact bytes.',
              likelihood: 'low', remediationEffort: 'low',
              remediation: 'Pin the base image by digest (image:tag@sha256:...) for reproducible, tamper-evident builds.',
              complianceMappings: [
                cis('4.4', 'Mapped control impact: relevant to image content integrity.')
              ]
            }));
          }
        } else if (kind === 'USER') {
          sawUser = true;
          lastUser = args.split(/\s+/)[0] || '';
          lastUserLine = ins.line;
          const u = lastUser.toLowerCase();
          if (u === 'root' || u === '0') {
            sawExplicitRoot = true;
            explicitRootLine = ins.line;
          }
        } else if (kind === 'HEALTHCHECK') {
          if (!/^\s*NONE\b/i.test(args)) hasHealthcheck = true;
        } else if (kind === 'CMD' || kind === 'ENTRYPOINT') {
          hasService = true;
        } else if (kind === 'COPY' || kind === 'ADD') {
          // Strip flags like --chown=, --from=.
          const a = args.replace(/--\S+\s*/g, '');
          if (SECRET_FILE_RE.test(' ' + a)) {
            out.push(finding({
              ruleId: 'container.docker-secret-baked',
              name: 'Sensitive file copied into the image',
              severity: 'high', confidence: 'medium', cwe: 'CWE-538',
              file: entry.path, line: ins.line, snippet: (kind + ' ' + args),
              impact: 'Secrets baked into an image layer persist forever and are extractable by anyone who can pull the image.',
              likelihood: 'medium', remediationEffort: 'medium',
              remediation: 'Never COPY/ADD .env, keys, certs or credentials into an image; inject secrets at runtime or use build secrets (--mount=type=secret).',
              complianceMappings: [
                cis('4.10', 'Mapped control impact: do not store secrets in Dockerfiles/images.'),
                nist('IA-5', 'Authenticator management: relevant to embedded credentials.')
              ]
            }));
          }
          if (kind === 'ADD') {
            const src = args.replace(/--\S+\s*/g, '').split(/\s+/)[0] || '';
            if (ADD_URL_RE.test(src)) {
              out.push(finding({
                ruleId: 'container.docker-add-remote-url',
                name: 'ADD used to fetch a remote URL',
                severity: 'low', confidence: 'high', cwe: 'CWE-494',
                file: entry.path, line: ins.line, snippet: (kind + ' ' + args),
                impact: 'ADD <url> fetches over the network without integrity verification and can pull tampered content.',
                likelihood: 'low', remediationEffort: 'low',
                remediation: 'Use COPY for local files; for remote artifacts, download with curl/wget and verify a checksum/signature.',
                complianceMappings: [cis('4.9', 'Mapped control impact: prefer COPY over ADD; verify remote content.')]
              }));
            }
          }
        } else if (kind === 'ENV' || kind === 'ARG') {
          // ENV KEY=value / ENV KEY value ; ARG KEY=value
          const pairs = args.match(/([A-Za-z0-9_]+)\s*=\s*("[^"]*"|'[^']*'|\S+)/g) || [];
          let flagged = false;
          pairs.forEach(p => {
            const eq = p.indexOf('=');
            const name = p.slice(0, eq).trim();
            const val = p.slice(eq + 1).trim().replace(/^['"]|['"]$/g, '');
            if (!flagged && SECRET_NAME_RE.test(name) && val !== '' &&
                !/^\$\{?\w+\}?$/.test(val)) {
              flagged = true;
              out.push(finding({
                ruleId: 'container.docker-secret-env',
                name: 'Secret-like value hardcoded in ' + kind,
                severity: 'high', confidence: 'medium', cwe: 'CWE-798',
                file: entry.path, line: ins.line, snippet: (kind + ' ' + args),
                impact: 'A secret set via ENV/ARG is baked into image metadata/layers and visible via docker history/inspect.',
                likelihood: 'medium', remediationEffort: 'medium',
                remediation: 'Pass secrets at runtime (env file/secret manager) or use build secrets; never hardcode them in ENV/ARG.',
                complianceMappings: [
                  cis('4.10', 'Mapped control impact: do not store secrets in Dockerfiles.'),
                  nist('IA-5', 'Authenticator management: relevant to embedded credentials.')
                ]
              }));
            }
          });
        } else if (kind === 'RUN') {
          const a = args;
          if (REMOTE_EXEC_RE.test(a)) {
            out.push(finding({
              ruleId: 'container.docker-remote-exec',
              name: 'Remote script piped directly to a shell in RUN',
              severity: 'high', confidence: 'high', cwe: 'CWE-494',
              file: entry.path, line: ins.line, snippet: a,
              impact: 'curl|wget piped to sh/bash executes unverified remote code at build time — a supply-chain RCE vector.',
              likelihood: 'medium', remediationEffort: 'medium',
              remediation: 'Download the script to a file, verify its checksum/signature, then execute it; pin to a specific release.',
              complianceMappings: [
                cis('4.6', 'Mapped control impact: relevant to trusted build steps.'),
                nist('SI-7', 'Software integrity: relevant to verifying fetched content.')
              ]
            }));
          }
          if (SSH_INSTALL_RE.test(a)) {
            out.push(finding({
              ruleId: 'container.docker-ssh-server',
              name: 'SSH server installed in the image',
              severity: 'medium', confidence: 'medium', cwe: 'CWE-250',
              file: entry.path, line: ins.line, snippet: a,
              impact: 'Running sshd inside a container enlarges the attack surface and is discouraged; use docker exec/kubectl instead.',
              likelihood: 'low', remediationEffort: 'medium',
              remediation: 'Do not run an SSH daemon in containers; use orchestrator-native exec for shell access.',
              complianceMappings: [cis('4.x', 'Mapped control impact: minimize installed packages/services in images.')]
            }));
          }
          if (SUDO_RE.test(a)) {
            out.push(finding({
              ruleId: 'container.docker-sudo-deep',
              name: 'sudo installed or used in RUN',
              severity: 'low', confidence: 'low', cwe: 'CWE-250',
              file: entry.path, line: ins.line, snippet: a,
              impact: 'sudo in a container indicates privilege juggling that is unnecessary; build steps should run as the intended user.',
              likelihood: 'low', remediationEffort: 'low',
              remediation: 'Drop sudo; set a non-root USER and only escalate at build time where strictly required.',
              complianceMappings: [cis('4.1', 'Mapped control impact: run containers as a non-root user.')]
            }));
          }
          // Package-manager cache hygiene (best-effort, per RUN).
          if (/\bapt-get\s+install\b/i.test(a)) aptInstall = true;
          if (/rm\s+-rf\s+\/var\/lib\/apt\/lists/i.test(a)) aptCleaned = true;
          if (/\bapk\s+add\b/i.test(a)) {
            apkInstall = true;
            if (/--no-cache\b/i.test(a)) apkNoCache = true;
          }
          if (/\byum\s+install\b/i.test(a) || /\bdnf\s+install\b/i.test(a)) yumInstall = true;
          if (/(?:yum|dnf)\s+clean\s+all\b/i.test(a)) yumCleaned = true;
        }
      } catch (e) { /* skip instruction */ }
    });

    // ---- Runs-as-root determination ----
    let runsAsRoot = false;
    if (!sawUser) {
      runsAsRoot = true;
      out.push(finding({
        ruleId: 'container.docker-runs-as-root',
        name: 'Container runs as root (no USER directive)',
        severity: 'high', confidence: 'high', cwe: 'CWE-250',
        file: entry.path, line: null, snippet: '',
        impact: 'Without a USER directive the process runs as root; a container breakout then has root on the host namespace.',
        likelihood: 'medium', remediationEffort: 'low',
        remediation: 'Add a non-root USER (e.g. create an app user and `USER app`) before the CMD/ENTRYPOINT.',
        complianceMappings: [
          cis('4.1', 'Mapped control impact: create a user for the container and run as non-root.'),
          nist('AC-6', 'Least privilege: relevant to dropping root in containers.')
        ]
      }));
    } else if (sawExplicitRoot && (lastUser || '').toLowerCase() === 'root' ||
               (lastUser || '').toLowerCase() === '0') {
      runsAsRoot = true;
      out.push(finding({
        ruleId: 'container.docker-user-root',
        name: 'Container explicitly set to run as root',
        severity: 'high', confidence: 'high', cwe: 'CWE-250',
        file: entry.path, line: lastUserLine || explicitRootLine, snippet: 'USER ' + lastUser,
        impact: 'The effective final USER is root, so the container process runs with full root privileges.',
        likelihood: 'medium', remediationEffort: 'low',
        remediation: 'Switch the final USER to a dedicated non-root account before the runtime command.',
        complianceMappings: [
          cis('4.1', 'Mapped control impact: run containers as a non-root user.'),
          nist('AC-6', 'Least privilege: relevant to dropping root in containers.')
        ]
      }));
    }
    if (runsAsRoot) summary.runsAsRoot = true;

    // ---- Missing HEALTHCHECK ----
    if (hasService && !hasHealthcheck) {
      out.push(finding({
        ruleId: 'container.docker-no-healthcheck',
        name: 'No HEALTHCHECK defined for the service image',
        severity: 'low', confidence: 'medium', cwe: 'CWE-754',
        file: entry.path, line: null, snippet: '',
        impact: 'Without a HEALTHCHECK the orchestrator cannot detect an unhealthy container and route traffic away from it.',
        likelihood: 'low', remediationEffort: 'low',
        remediation: 'Add a HEALTHCHECK instruction (or an orchestrator-level liveness/readiness probe) for the service.',
        complianceMappings: [cis('4.6', 'Mapped control impact: add HEALTHCHECK to images.')]
      }));
    }

    // ---- Package-manager cache hygiene ----
    if (aptInstall && !aptCleaned) {
      out.push(finding({
        ruleId: 'container.docker-apt-cache',
        name: 'apt-get install without cleaning the package lists',
        severity: 'low', confidence: 'medium', cwe: 'CWE-1188',
        file: entry.path, line: null, snippet: '',
        impact: 'Leftover /var/lib/apt/lists bloats the image and increases the attack surface of the final layer.',
        likelihood: 'low', remediationEffort: 'low',
        remediation: 'Append `&& rm -rf /var/lib/apt/lists/*` in the same RUN as apt-get install.',
        complianceMappings: [cis('4.x', 'Mapped control impact: minimize image size/content.')]
      }));
    }
    if (apkInstall && !apkNoCache) {
      out.push(finding({
        ruleId: 'container.docker-apk-cache',
        name: 'apk add without --no-cache',
        severity: 'low', confidence: 'medium', cwe: 'CWE-1188',
        file: entry.path, line: null, snippet: '',
        impact: 'Caching apk indexes bloats the image; --no-cache keeps the final layer lean.',
        likelihood: 'low', remediationEffort: 'low',
        remediation: 'Use `apk add --no-cache ...` so no package index is retained.',
        complianceMappings: [cis('4.x', 'Mapped control impact: minimize image size/content.')]
      }));
    }
    if (yumInstall && !yumCleaned) {
      out.push(finding({
        ruleId: 'container.docker-yum-cache',
        name: 'yum/dnf install without clean all',
        severity: 'low', confidence: 'medium', cwe: 'CWE-1188',
        file: entry.path, line: null, snippet: '',
        impact: 'Retained yum/dnf metadata bloats the image and adds unnecessary content to the final layer.',
        likelihood: 'low', remediationEffort: 'low',
        remediation: 'Append `&& yum clean all` (or `dnf clean all`) in the same RUN as the install.',
        complianceMappings: [cis('4.x', 'Mapped control impact: minimize image size/content.')]
      }));
    }
  }

  // ===========================================================================
  // docker-compose analyzer (line/key based — no real YAML parser)
  // ===========================================================================

  const MGMT_PORTS = ['22', '2375', '2376', '5432', '3306', '6379', '27017', '9200', '9300'];

  function analyzeCompose(entry, text, out, summary) {
    const ls = lines(text);
    let sawReadOnly = false;
    let sawService = false;
    for (let i = 0; i < ls.length; i++) {
      const line = ls[i];
      const ln = i + 1;
      const t = line.replace(/^\s+/, '');
      if (t === '' || t.charAt(0) === '#') continue;
      const lower = t.toLowerCase();

      try {
        // crude service-block detection (a key with no value at 2-space indent
        // under `services:`) — good enough for the read_only aggregate note.
        if (/^services\s*:/i.test(t)) sawService = true;
        if (/^read_only\s*:\s*true\b/i.test(t)) sawReadOnly = true;

        if (/^privileged\s*:\s*true\b/i.test(t)) {
          summary.privileged = true;
          out.push(finding({
            ruleId: 'container.compose-privileged',
            name: 'Service runs in privileged mode',
            severity: 'high', confidence: 'high', cwe: 'CWE-250',
            file: entry.path, line: ln, snippet: t,
            impact: 'privileged: true grants the container nearly all host capabilities and device access — effectively host root.',
            likelihood: 'high', remediationEffort: 'low',
            remediation: 'Remove privileged: true; grant only the specific capabilities/devices the workload truly needs.',
            complianceMappings: [
              cis('5.4', 'Mapped control impact: do not run privileged containers.'),
              nist('AC-6', 'Least privilege: relevant to container capability scope.')
            ]
          }));
        }
        if (/^network_mode\s*:\s*["']?host\b/i.test(t)) {
          summary.hostNetwork = true;
          out.push(finding({
            ruleId: 'container.compose-host-network',
            name: 'Service uses host network mode',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'network_mode: host removes network isolation; the container shares the host network stack and can reach loopback services.',
            likelihood: 'medium', remediationEffort: 'low',
            remediation: 'Use bridge/user-defined networks and publish only the required ports instead of host networking.',
            complianceMappings: [cis('5.9', 'Mapped control impact: do not share the host network namespace.')]
          }));
        }
        if (/^pid\s*:\s*["']?host\b/i.test(t)) {
          out.push(finding({
            ruleId: 'container.compose-host-pid',
            name: 'Service shares the host PID namespace',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'pid: host lets the container see and signal host processes, weakening isolation.',
            likelihood: 'low', remediationEffort: 'low',
            remediation: 'Remove pid: host so the container keeps its own PID namespace.',
            complianceMappings: [cis('5.15', 'Mapped control impact: do not share the host process namespace.')]
          }));
        }
        if (/^ipc\s*:\s*["']?host\b/i.test(t)) {
          out.push(finding({
            ruleId: 'container.compose-host-ipc',
            name: 'Service shares the host IPC namespace',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'ipc: host shares host shared-memory/IPC with the container, weakening isolation.',
            likelihood: 'low', remediationEffort: 'low',
            remediation: 'Remove ipc: host so the container keeps its own IPC namespace.',
            complianceMappings: [cis('5.16', 'Mapped control impact: do not share the host IPC namespace.')]
          }));
        }
        // cap_add list — may be inline `cap_add: [SYS_ADMIN]` or list items.
        if (/^cap_add\s*:/i.test(t) || /^-\s*(?:SYS_ADMIN|ALL|NET_ADMIN|SYS_PTRACE|NET_RAW)\b/i.test(t)) {
          const capLine = t;
          const dangerous = /\b(ALL|SYS_ADMIN)\b/i.test(capLine);
          const elevated = /\b(NET_ADMIN|SYS_PTRACE|NET_RAW)\b/i.test(capLine);
          // For a bare `cap_add:` header, peek at the same line; list items are
          // matched on their own line by the `-` alternative above.
          if (dangerous || elevated) {
            out.push(finding({
              ruleId: 'container.compose-cap-add',
              name: 'Service adds a dangerous Linux capability',
              severity: dangerous ? 'high' : 'medium', confidence: 'medium', cwe: 'CWE-250',
              file: entry.path, line: ln, snippet: capLine,
              impact: 'Capabilities like SYS_ADMIN/ALL/NET_ADMIN/SYS_PTRACE substantially weaken container isolation.',
              likelihood: 'medium', remediationEffort: 'low',
              remediation: 'Drop ALL capabilities and add back only the minimal set the workload requires.',
              complianceMappings: [cis('5.3', 'Mapped control impact: restrict Linux kernel capabilities.')]
            }));
          }
        }
        if (/^-?\s*(?:seccomp|apparmor)\s*[:=]\s*unconfined\b/i.test(t) ||
            /unconfined/.test(lower) && /security_opt|seccomp|apparmor/.test(lower)) {
          out.push(finding({
            ruleId: 'container.compose-unconfined',
            name: 'Service disables seccomp/AppArmor confinement',
            severity: 'medium', confidence: 'medium', cwe: 'CWE-693',
            file: entry.path, line: ln, snippet: t,
            impact: 'An unconfined seccomp/AppArmor profile removes a key syscall/MAC defense layer.',
            likelihood: 'low', remediationEffort: 'low',
            remediation: 'Keep the default seccomp profile and an AppArmor/SELinux profile enabled; do not use unconfined.',
            complianceMappings: [cis('5.21', 'Mapped control impact: do not disable the default seccomp profile.')]
          }));
        }
        // Volume mounts: docker.sock, host root, /etc.
        if (/\/var\/run\/docker\.sock/i.test(t) || /\/run\/docker\.sock/i.test(t)) {
          out.push(finding({
            ruleId: 'container.compose-docker-sock',
            name: 'Docker socket mounted into a container',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'Mounting /var/run/docker.sock gives the container full control of the Docker daemon — equivalent to host root.',
            likelihood: 'high', remediationEffort: 'medium',
            remediation: 'Do not mount the Docker socket; use a scoped API proxy or rootless/sysbox alternatives if daemon access is required.',
            complianceMappings: [
              cis('5.31', 'Mapped control impact: do not mount the Docker socket inside containers.'),
              nist('AC-6', 'Least privilege: relevant to daemon-level access.')
            ]
          }));
        } else if (/^-?\s*["']?\/\s*:/.test(t) || /^-?\s*["']?\/:[^/]/.test(t)) {
          out.push(finding({
            ruleId: 'container.compose-host-root-mount',
            name: 'Host root filesystem mounted into a container',
            severity: 'high', confidence: 'medium', cwe: 'CWE-552',
            file: entry.path, line: ln, snippet: t,
            impact: 'Mounting host / into a container exposes the entire host filesystem for read/write.',
            likelihood: 'medium', remediationEffort: 'low',
            remediation: 'Mount only the specific paths required, read-only where possible — never the host root.',
            complianceMappings: [cis('5.5', 'Mapped control impact: do not mount sensitive host directories.')]
          }));
        } else if (/^-?\s*["']?\/etc(?:\/[\w.-]*)?\s*:/.test(t)) {
          out.push(finding({
            ruleId: 'container.compose-host-etc-mount',
            name: 'Host /etc mounted into a container',
            severity: 'medium', confidence: 'medium', cwe: 'CWE-552',
            file: entry.path, line: ln, snippet: t,
            impact: 'Mounting host /etc can expose or allow tampering with sensitive host configuration.',
            likelihood: 'low', remediationEffort: 'low',
            remediation: 'Avoid mounting host /etc; pass only the specific config files needed, read-only.',
            complianceMappings: [cis('5.5', 'Mapped control impact: do not mount sensitive host directories.')]
          }));
        }
        // image: ref tracking + tag checks.
        const im = /^image\s*:\s*["']?([^"'\s#]+)/i.exec(t);
        if (im) {
          const ref = im[1];
          const f = parseFrom(ref, null);
          if (f && !f.fromStage) {
            summary.images.push({ ref: f.ref, pinned: !!f.pinned, latest: !!f.latest });
            if (f.latest) {
              out.push(finding({
                ruleId: 'container.compose-image-latest',
                name: f.tag ? 'Compose image pinned to :latest' : 'Compose image has no tag (implicit latest)',
                severity: 'medium', confidence: 'high', cwe: 'CWE-1104',
                file: entry.path, line: ln, snippet: t,
                impact: 'A moving/untagged image yields non-reproducible deployments and can silently pull a vulnerable image.',
                likelihood: 'medium', remediationEffort: 'low',
                remediation: 'Pin the image to a specific version (and ideally @sha256 digest) instead of :latest/untagged.',
                complianceMappings: [cis('4.4', 'Mapped control impact: use pinned, current image tags.')]
              }));
            }
          }
        }
        // Port bindings exposing management ports on all interfaces.
        const portMatch = /^-?\s*["']?(\d+\.\d+\.\d+\.\d+:)?(\d+):(\d+)/.exec(t);
        if (portMatch && /^-/.test(t) || /^ports\s*:/.test(t) === false && portMatch) {
          // Evaluate only on list-item-looking port lines.
          if (/^-/.test(t) && portMatch) {
            const hostIface = portMatch[1] || '';
            const containerPort = portMatch[3];
            const exposedAll = hostIface === '' || hostIface === '0.0.0.0:';
            if (exposedAll && MGMT_PORTS.indexOf(containerPort) >= 0) {
              out.push(finding({
                ruleId: 'container.compose-exposed-mgmt-port',
                name: 'Management/database port exposed on all interfaces',
                severity: 'medium', confidence: 'medium', cwe: 'CWE-668',
                file: entry.path, line: ln, snippet: t,
                impact: 'Publishing a database/management port (e.g. ' + containerPort + ') on 0.0.0.0 can expose it to untrusted networks.',
                likelihood: 'medium', remediationEffort: 'low',
                remediation: 'Bind sensitive ports to 127.0.0.1 (or an internal network) and reach them via a private network, not 0.0.0.0.',
                complianceMappings: [cis('5.7', 'Mapped control impact: restrict published ports to required interfaces.')]
              }));
            }
          }
        }
        // Inline secret in environment.
        const envSecret = /^-?\s*([A-Za-z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|APIKEY|API_KEY|ACCESS_KEY|PRIVATE_KEY)[A-Za-z0-9_]*)\s*[:=]\s*["']?([^"'\s#$][^"'#]*)/i.exec(t);
        if (envSecret) {
          const val = (envSecret[2] || '').trim();
          if (val !== '' && !/^\$\{?\w+\}?/.test(val)) {
            out.push(finding({
              ruleId: 'container.compose-inline-secret',
              name: 'Inline secret in compose environment',
              severity: 'medium', confidence: 'medium', cwe: 'CWE-798',
              file: entry.path, line: ln, snippet: t,
              impact: 'A literal secret in environment ends up in source control and the compose-rendered config.',
              likelihood: 'medium', remediationEffort: 'low',
              remediation: 'Reference secrets via env_file / Docker secrets / ${VAR} from an untracked .env, not literal values.',
              complianceMappings: [nist('IA-5', 'Authenticator management: relevant to embedded credentials.')]
            }));
          }
        }
      } catch (e) { /* skip line */ }
    }

    // Aggregated read_only note (one per compose file, not per service).
    if (sawService && !sawReadOnly) {
      out.push(finding({
        ruleId: 'container.compose-no-read-only',
        name: 'No service declares a read-only root filesystem',
        severity: 'low', confidence: 'low', cwe: 'CWE-732',
        file: entry.path, line: null, snippet: '',
        impact: 'A writable container root filesystem lets an attacker drop tools/persistence into the running container.',
        likelihood: 'low', remediationEffort: 'low',
        remediation: 'Set read_only: true on services and mount explicit writable volumes for paths that need writes.',
        complianceMappings: [cis('5.12', 'Mapped control impact: mount the container root filesystem read-only.')]
      }));
    }
  }

  // ===========================================================================
  // Kubernetes analyzer (best-effort, light — rules-iac covers several of these)
  // ===========================================================================

  function analyzeK8s(entry, text, out, summary) {
    const ls = lines(text);
    let sawRunAsNonRoot = false;
    let sawRunAsNonRootFalse = false;
    let runAsNonRootLine = null;
    let sawResourceLimits = false;
    let hasContainers = false;

    for (let i = 0; i < ls.length; i++) {
      const t = ls[i].replace(/^\s+/, '');
      const ln = i + 1;
      if (t === '' || t.charAt(0) === '#') continue;
      try {
        if (/^containers\s*:/i.test(t) || /^-?\s*image\s*:/i.test(t)) hasContainers = true;
        if (/^limits\s*:/i.test(t)) sawResourceLimits = true;

        if (/^privileged\s*:\s*true\b/i.test(t)) {
          summary.privileged = true;
          out.push(finding({
            ruleId: 'container.k8s-privileged',
            name: 'Pod/container securityContext privileged',
            severity: 'high', confidence: 'high', cwe: 'CWE-250',
            file: entry.path, line: ln, snippet: t,
            impact: 'A privileged container has nearly all host capabilities and device access — effectively host root.',
            likelihood: 'high', remediationEffort: 'low',
            remediation: 'Set securityContext.privileged: false; grant only the minimal capabilities required.',
            complianceMappings: [cisK8s('5.2.1', 'Mapped control impact: minimize privileged containers.')]
          }));
        }
        if (/^hostNetwork\s*:\s*true\b/i.test(t)) {
          summary.hostNetwork = true;
          out.push(finding({
            ruleId: 'container.k8s-host-network',
            name: 'Pod uses hostNetwork',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'hostNetwork shares the node network namespace, removing pod-level network isolation.',
            likelihood: 'medium', remediationEffort: 'low',
            remediation: 'Set hostNetwork: false unless absolutely required.',
            complianceMappings: [cisK8s('5.2.4', 'Mapped control impact: minimize host network sharing.')]
          }));
        }
        if (/^hostPID\s*:\s*true\b/i.test(t) || /^hostIPC\s*:\s*true\b/i.test(t)) {
          out.push(finding({
            ruleId: 'container.k8s-host-namespace',
            name: 'Pod shares a host namespace (hostPID/hostIPC)',
            severity: 'high', confidence: 'high', cwe: 'CWE-668',
            file: entry.path, line: ln, snippet: t,
            impact: 'Sharing host PID/IPC namespaces weakens isolation between the pod and the node.',
            likelihood: 'low', remediationEffort: 'low',
            remediation: 'Set hostPID/hostIPC to false.',
            complianceMappings: [cisK8s('5.2.2', 'Mapped control impact: minimize host namespace sharing.')]
          }));
        }
        if (/^runAsNonRoot\s*:\s*true\b/i.test(t)) sawRunAsNonRoot = true;
        if (/^runAsNonRoot\s*:\s*false\b/i.test(t)) { sawRunAsNonRootFalse = true; runAsNonRootLine = ln; }
        if (/^allowPrivilegeEscalation\s*:\s*true\b/i.test(t)) {
          out.push(finding({
            ruleId: 'container.k8s-priv-escalation',
            name: 'Container allows privilege escalation',
            severity: 'medium', confidence: 'medium', cwe: 'CWE-250',
            file: entry.path, line: ln, snippet: t,
            impact: 'allowPrivilegeEscalation: true lets a process gain more privileges than its parent (e.g. via setuid).',
            likelihood: 'medium', remediationEffort: 'low',
            remediation: 'Set allowPrivilegeEscalation: false in the container securityContext.',
            complianceMappings: [cisK8s('5.2.5', 'Mapped control impact: minimize privilege escalation.')]
          }));
        }
        const im = /^-?\s*image\s*:\s*["']?([^"'\s#]+)/i.exec(t);
        if (im) {
          const f = parseFrom(im[1], null);
          if (f && !f.fromStage) {
            summary.images.push({ ref: f.ref, pinned: !!f.pinned, latest: !!f.latest });
            if (f.latest) {
              out.push(finding({
                ruleId: 'container.k8s-image-latest',
                name: f.tag ? 'K8s container image pinned to :latest' : 'K8s container image has no tag',
                severity: 'medium', confidence: 'high', cwe: 'CWE-1104',
                file: entry.path, line: ln, snippet: t,
                impact: 'imagePullPolicy/:latest yields non-reproducible rollouts and can pull a vulnerable image.',
                likelihood: 'medium', remediationEffort: 'low',
                remediation: 'Pin container images to a specific version and ideally a @sha256 digest.',
                complianceMappings: [cisK8s('5.x', 'Mapped control impact: use pinned image references.')]
              }));
            }
          }
        }
      } catch (e) { /* skip line */ }
    }

    if (hasContainers) {
      if (sawRunAsNonRootFalse || !sawRunAsNonRoot) {
        out.push(finding({
          ruleId: 'container.k8s-run-as-root',
          name: sawRunAsNonRootFalse ? 'Container explicitly allowed to run as root' : 'runAsNonRoot not enforced',
          severity: 'medium', confidence: 'medium', cwe: 'CWE-250',
          file: entry.path, line: runAsNonRootLine, snippet: '',
          impact: 'Without runAsNonRoot: true a container may run as root, so a breakout has elevated privileges on the node.',
          likelihood: 'medium', remediationEffort: 'low',
          remediation: 'Set securityContext.runAsNonRoot: true (and a non-zero runAsUser) at pod or container scope.',
          complianceMappings: [cisK8s('5.2.6', 'Mapped control impact: run containers as a non-root user.')]
        }));
        summary.runsAsRoot = true;
      }
      if (!sawResourceLimits) {
        out.push(finding({
          ruleId: 'container.k8s-no-limits',
          name: 'Container has no resource limits',
          severity: 'low', confidence: 'low', cwe: 'CWE-400',
          file: entry.path, line: null, snippet: '',
          impact: 'Without resources.limits a container can exhaust node CPU/memory, enabling noisy-neighbour or DoS conditions.',
          likelihood: 'low', remediationEffort: 'low',
          remediation: 'Define resources.limits (and requests) for CPU and memory on each container.',
          complianceMappings: [cisK8s('5.x', 'Mapped control impact: set resource limits on workloads.')]
        }));
      }
    }
  }

  // ===========================================================================
  // Score
  // ===========================================================================

  function clampInt(n) {
    if (isNaN(n)) return 0;
    n = Math.round(n);
    return n < 0 ? 0 : (n > 100 ? 100 : n);
  }

  // Score formula (0..100, higher = better container posture):
  //   start at 100, then subtract per container finding by severity:
  //     critical -40, high -18, medium -7, low -2, info -0
  //   summed over all container findings, clamped to [0, 100].
  function computeScore(findings) {
    let score = 100;
    asArray(findings).forEach(f => {
      switch (f && f.severity) {
        case 'critical': score -= 40; break;
        case 'high': score -= 18; break;
        case 'medium': score -= 7; break;
        case 'low': score -= 2; break;
        default: break; // info / unknown
      }
    });
    return clampInt(score);
  }

  // ===========================================================================
  // main
  // ===========================================================================

  function analyze(entries) {
    const list = asArray(entries);
    const findings = [];
    const summary = {
      dockerfiles: 0, composeFiles: 0, k8sFiles: 0,
      images: [], runsAsRoot: false, privileged: false, hostNetwork: false,
      score: 100
    };

    try {
      list.forEach(e => {
        try {
          const text = scanText(e);
          if (text == null) return;
          const path = e.path;
          if (!path) return;

          if (isDockerfile(path)) {
            summary.dockerfiles++;
            analyzeDockerfile(e, text, findings, summary);
          } else if (isComposeFile(path)) {
            summary.composeFiles++;
            analyzeCompose(e, text, findings, summary);
          } else if (isYaml(path) && looksLikeK8s(text)) {
            summary.k8sFiles++;
            analyzeK8s(e, text, findings, summary);
          }
        } catch (inner) { /* skip entry */ }
      });

      summary.score = computeScore(findings);
    } catch (e) {
      // degrade: return whatever was assembled.
    }

    return { findings: findings, summary: summary };
  }

  CITADEL.reviewContainer = { analyze: analyze };
})(window);
