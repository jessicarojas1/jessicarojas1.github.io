/* CITADEL — IaC & cloud posture pack.
 * Deeper infrastructure-as-code / cloud misconfiguration checks (Terraform,
 * CloudFormation, Kubernetes, Dockerfiles, cloud IAM) — beyond the core set in
 * rules-extra.js. Same rule shape; appended to CITADEL.rules and run by the
 * standard scanner. Categories map to the usual config/authz/transport controls.
 * window.CITADEL.rules (extended)
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const TF = ['Terraform', 'HCL'];
  const EXTRA = [
    /* ---- Terraform / cloud storage ---- */
    { id: 'tf-s3-public-acl', name: 'S3 bucket with public ACL', category: 'config',
      severity: 'high', cwe: 'CWE-732', langs: TF, confidence: 'high',
      re: /acl\s*=\s*"public-read(?:-write)?"/i,
      remediation: 'Use private ACLs and a bucket policy; enable S3 Block Public Access.' },
    { id: 'tf-block-public-false', name: 'S3 Block Public Access disabled', category: 'config',
      severity: 'high', cwe: 'CWE-732', langs: TF, confidence: 'high',
      re: /block_public_(?:acls|policy|buckets)\s*=\s*false/i,
      remediation: 'Set all block_public_* settings to true unless the bucket is intentionally public.' },
    { id: 'tf-encryption-false', name: 'Resource encryption explicitly disabled', category: 'crypto',
      severity: 'high', cwe: 'CWE-311', langs: TF, confidence: 'medium',
      re: /encrypted\s*=\s*false|storage_encrypted\s*=\s*false/i,
      remediation: 'Enable encryption at rest (KMS-managed where possible).' },
    { id: 'tf-versioning-false', name: 'S3 bucket versioning disabled', category: 'config',
      severity: 'low', cwe: 'CWE-1188', langs: TF, confidence: 'low',
      re: /versioning\s*\{[\s\S]{0,60}?enabled\s*=\s*false/i,
      remediation: 'Enable versioning to protect against accidental deletion/ransomware.' },
    /* ---- Terraform / network & IAM ---- */
    { id: 'tf-hardcoded-cloud-cred', name: 'Hardcoded cloud credentials in Terraform', category: 'secrets',
      severity: 'critical', cwe: 'CWE-798', langs: TF, confidence: 'medium',
      re: /(access_key|secret_key|client_secret|password)\s*=\s*"[A-Za-z0-9\/+_-]{12,}"/i,
      remediation: 'Use variables, a secrets manager, or provider auth — never literal credentials in HCL.' },
    { id: 'tf-http-module', name: 'Terraform module/source over plain HTTP', category: 'transport',
      severity: 'medium', cwe: 'CWE-494', langs: TF, confidence: 'medium',
      re: /source\s*=\s*"http:\/\//i,
      remediation: 'Fetch modules over HTTPS (or a pinned registry/git ref) to ensure integrity.' },
    { id: 'tf-public-ip', name: 'Instance assigned a public IP', category: 'config',
      severity: 'medium', cwe: 'CWE-668', langs: TF, confidence: 'low',
      re: /associate_public_ip_address\s*=\s*true|map_public_ip_on_launch\s*=\s*true/i,
      remediation: 'Place workloads in private subnets behind a NAT/ALB unless public exposure is required.' },
    /* ---- CloudFormation / cloud IAM (JSON or YAML) ---- */
    { id: 'cfn-iam-wildcard-action', name: 'IAM policy grants Action "*"', category: 'authz',
      severity: 'high', cwe: 'CWE-732', langs: '*', confidence: 'medium',
      re: /"Action"\s*:\s*"\*"/,
      remediation: 'Scope IAM actions to the minimum required; avoid "*" (full admin).' },
    { id: 'cfn-iam-wildcard-resource', name: 'IAM policy with Resource "*"', category: 'authz',
      severity: 'medium', cwe: 'CWE-732', langs: '*', confidence: 'low',
      re: /"Resource"\s*:\s*"\*"/,
      remediation: 'Restrict the Resource ARNs a policy applies to.' },
    { id: 'cfn-s3-public', name: 'CloudFormation S3 public access', category: 'config',
      severity: 'high', cwe: 'CWE-732', langs: '*', confidence: 'medium',
      re: /"AccessControl"\s*:\s*"PublicRead(?:Write)?"/,
      remediation: 'Use private buckets + Block Public Access; serve via CloudFront/OAC if public.' },
    /* ---- Kubernetes (YAML) ---- */
    { id: 'k8s-hostpath', name: 'Kubernetes hostPath volume mount', category: 'config',
      severity: 'high', cwe: 'CWE-552', langs: ['YAML'], confidence: 'medium',
      re: /hostPath\s*:/,
      remediation: 'Avoid hostPath mounts (node filesystem access); use PVCs/configMaps/secrets.' },
    { id: 'k8s-automount-sa', name: 'ServiceAccount token auto-mounted', category: 'config',
      severity: 'medium', cwe: 'CWE-250', langs: ['YAML'], confidence: 'low',
      re: /automountServiceAccountToken\s*:\s*true/,
      remediation: 'Set automountServiceAccountToken: false unless the pod needs the API.' },
    { id: 'k8s-readonly-rootfs-false', name: 'Container root filesystem is writable', category: 'config',
      severity: 'low', cwe: 'CWE-732', langs: ['YAML'], confidence: 'low',
      re: /readOnlyRootFilesystem\s*:\s*false/,
      remediation: 'Set readOnlyRootFilesystem: true and mount writable paths explicitly.' },
    { id: 'k8s-cap-sysadmin', name: 'Container adds SYS_ADMIN capability', category: 'config',
      severity: 'high', cwe: 'CWE-250', langs: ['YAML'], confidence: 'medium',
      re: /add\s*:\s*\[?[\s\S]{0,40}?SYS_ADMIN/,
      remediation: 'Do not add SYS_ADMIN; drop ALL capabilities and add only what is required.' },
    { id: 'k8s-default-namespace', name: 'Workload deployed to the default namespace', category: 'config',
      severity: 'info', cwe: 'CWE-1188', langs: ['YAML'], confidence: 'low',
      re: /namespace\s*:\s*default\b/,
      remediation: 'Use dedicated namespaces with RBAC + network policies, not "default".' },
    /* ---- Azure / GCP ---- */
    { id: 'azure-blob-public', name: 'Azure storage allows public blob access', category: 'config',
      severity: 'high', cwe: 'CWE-732', langs: '*', confidence: 'medium',
      re: /allow(?:_b|B)lob_?[Pp]ublic_?[Aa]ccess\s*[:=]\s*true/,
      remediation: 'Disable public blob access; use SAS tokens or private endpoints.' },
    { id: 'gcp-bucket-allusers', name: 'GCP resource granted to allUsers/allAuthenticatedUsers', category: 'authz',
      severity: 'high', cwe: 'CWE-732', langs: '*', confidence: 'high',
      re: /"?(allUsers|allAuthenticatedUsers)"?/,
      remediation: 'Never bind IAM to allUsers/allAuthenticatedUsers; grant least-privilege principals.' },
    /* ---- Dockerfile ---- */
    { id: 'docker-sudo', name: 'Dockerfile installs/uses sudo', category: 'config',
      severity: 'low', cwe: 'CWE-250', langs: ['Dockerfile'], confidence: 'low',
      re: /\bsudo\b/,
      remediation: 'Containers should not need sudo; set a non-root USER and drop privileges at build time.' }
  ];
  (CITADEL.rules = CITADEL.rules || []).push.apply(CITADEL.rules, EXTRA);
})(window);
