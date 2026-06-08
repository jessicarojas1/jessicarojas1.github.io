/* CITADEL — CI/CD pipeline security pack.
 * Detects dangerous patterns in CI/CD definitions (GitHub Actions, GitLab CI,
 * generic pipelines): privilege/secret exposure, untrusted-input script
 * injection, mutable action refs, debug trace leaks. Same rule shape; appended
 * to CITADEL.rules and run by the standard scanner. Mostly YAML.
 * window.CITADEL.rules (extended)
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const EXTRA = [
    { id: 'cicd-pr-target', name: 'GitHub Actions pull_request_target trigger', category: 'authz',
      severity: 'high', cwe: 'CWE-250', langs: ['YAML'], confidence: 'medium',
      re: /\bpull_request_target\b/,
      remediation: 'pull_request_target runs with repo secrets and write token in the context of a fork PR. Avoid checking out/executing PR code, or use the safer pull_request trigger.' },
    { id: 'cicd-script-injection', name: 'Untrusted CI input interpolated into a step', category: 'injection',
      severity: 'high', cwe: 'CWE-94', langs: ['YAML'], confidence: 'medium',
      re: /\$\{\{\s*github\.event\.(?:issue|pull_request|comment|review|head_commit|discussion)\b[^}]*\}\}/,
      remediation: 'Never inline ${{ github.event.* }} into run scripts — pass it via an env var and quote it; attacker-controlled titles/branches can inject shell commands.' },
    { id: 'cicd-secret-echo', name: 'CI step prints a secret to logs', category: 'secrets',
      severity: 'high', cwe: 'CWE-532', langs: ['YAML'], confidence: 'medium',
      re: /echo\s+["']?\$\{\{\s*secrets\./i,
      remediation: 'Do not echo secrets; they end up in build logs. Use them only as masked env vars passed to tools.' },
    { id: 'cicd-permissions-write-all', name: 'Workflow token granted write-all', category: 'authz',
      severity: 'medium', cwe: 'CWE-250', langs: ['YAML'], confidence: 'high',
      re: /permissions\s*:\s*write-all/,
      remediation: 'Set least-privilege permissions (default read-only; grant specific write scopes per job).' },
    { id: 'cicd-action-mutable-ref', name: 'GitHub Action pinned to a mutable ref (@main/@master)', category: 'supply-chain',
      severity: 'medium', cwe: 'CWE-829', langs: ['YAML'], confidence: 'medium',
      re: /uses\s*:\s*[\w.-]+\/[\w.-]+@(?:main|master)\b/,
      remediation: 'Pin third-party actions to a full commit SHA (not a branch/tag) to prevent supply-chain tampering.' },
    { id: 'cicd-curl-pipe-sh', name: 'CI step pipes a download into a shell', category: 'injection',
      severity: 'medium', cwe: 'CWE-494', langs: ['YAML'], confidence: 'medium',
      re: /curl\s[^\n|]*\|\s*(?:sudo\s+)?(?:ba)?sh\b/i,
      remediation: 'Download to a file, verify a checksum/signature, then execute — never curl | sh in a pipeline.' },
    { id: 'cicd-gitlab-debug-trace', name: 'GitLab CI debug trace enabled (leaks secrets)', category: 'secrets',
      severity: 'high', cwe: 'CWE-532', langs: ['YAML'], confidence: 'high',
      re: /CI_DEBUG_TRACE\s*:\s*["']?true/i,
      remediation: 'CI_DEBUG_TRACE exposes masked variables/secrets in job logs. Never enable it on shared/production pipelines.' },
    { id: 'cicd-self-hosted-runner', name: 'Self-hosted runner', category: 'config',
      severity: 'info', cwe: 'CWE-1188', langs: ['YAML'], confidence: 'low',
      re: /runs-on\s*:\s*\[?\s*["']?self-hosted/,
      remediation: 'Self-hosted runners on public repos can be abused by fork PRs; isolate them and restrict which workflows use them.' }
  ];
  (CITADEL.rules = CITADEL.rules || []).push.apply(CITADEL.rules, EXTRA);
})(window);
