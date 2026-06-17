/* CITADEL — canonical type definitions for the normalized finding schema and
 * report shape. Plain-JS project, so these live as ambient declarations that
 * editors (VS Code/tsserver) pick up for IntelliSense + light type-checking,
 * and as the single documented contract shared by the browser tier and the
 * deep-scan backend. Mirror any field change here.
 */

/** Severity, normalized across every tool/engine. */
type CitadelSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info';

/** Confidence in a finding. */
type CitadelConfidence = 'high' | 'medium' | 'low';

/** How a finding was produced: a real scanner (data-flow/tool "confirmed") vs.
 *  the heuristic pattern engine ("potential"). */
type CitadelDetection = 'scanner' | 'heuristic';

/** Coarse classification of what a finding represents. */
type CitadelKind = 'vuln' | 'secret' | 'cve' | 'malware' | 'pii' | 'license' | 'quality' | 'policy';

/** Triage state (user-set), keyed by fingerprint. */
type CitadelDisposition = 'open' | 'accepted' | 'false-positive' | 'remediated' | 'na';

/** The ONE normalized finding shape every tier/scanner emits. */
interface CitadelFinding {
  /** Stable, line-drift-resistant identity (CITADEL.fingerprint.of). */
  fingerprint?: string;
  /** Source rule/check id (e.g. 'sql-concat', a Semgrep check_id). */
  ruleId?: string;
  /** Human-readable title. */
  name: string;
  /** Weakness category (drives compliance crosswalk). */
  category: string;
  severity: CitadelSeverity;
  confidence?: CitadelConfidence;
  /** Primary CWE, e.g. 'CWE-89'. */
  cwe?: string | null;
  /** Repo-relative file path. */
  file?: string;
  line?: number;
  /** The offending source line (trimmed). */
  snippet?: string;
  /** Full untrimmed source line (drives exact auto-fix regions); set when short. */
  lineText?: string;
  /** Textual remediation guidance. */
  remediation?: string;
  /** Producing scanner ('heuristic', 'semgrep', 'trivy', 'codeql', …). */
  source?: string;
  /** All tools that reported this fingerprint after merge. */
  sources?: string[];
  /** True when user input flows into this sink (intra-file taint). */
  tainted?: boolean;
  /** scanner vs heuristic. */
  detection?: CitadelDetection;
  /** true when detection === 'scanner'. */
  confirmed?: boolean;
  kind?: CitadelKind;
  /** Triage state (open by default). */
  disposition?: CitadelDisposition;
}

/** Per-scanner status in a deep scan. */
interface CitadelScannerStatus {
  tool: string;
  available: boolean;
  status: 'ok' | 'unavailable' | 'failed';
  warning?: string | null;
  /** Detected CLI version when known. */
  version?: string | null;
  findings: number;
}

/** Scoring block. */
interface CitadelScoring {
  sev: Record<CitadelSeverity, number>;
  /** 0–100, higher is better. */
  security: number;
  quality: number;
  overall: number;
  /** 0–100, higher = worse. */
  risk?: number;
  riskBand?: 'Minimal' | 'Low' | 'Moderate' | 'High' | 'Critical';
  grade: 'A' | 'B' | 'C' | 'D' | 'E' | 'F';
}

/** The unified report both tiers produce and the SPA renders. */
interface CitadelReport {
  meta: {
    scannedAt: string;
    fileCount?: number;
    totalBytes?: number;
    engine?: 'deep' | string;
    source?: string;
    scanners?: CitadelScannerStatus[];
    scanSummary?: { ran: number; unavailable: number; failed: number; total: number } | null;
    warnings?: string[];
  };
  languages: { total: number; languages: Array<{ lang: string; bytes: number; pct: number }>; primary: string };
  findings: CitadelFinding[];
  sbom: { components: any[]; doc: any };
  binaries: any[];
  quality: { maintainability: number; commentRatio: number; loc: number; [k: string]: any };
  deployment: any[];
  licenses: any[];
  scoring: CitadelScoring;
  posture: any[];
}
