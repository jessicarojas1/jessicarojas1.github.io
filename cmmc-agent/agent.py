#!/usr/bin/env python3
"""
CMMC 2.0 Level 2 Compliance Agent
Powered by Claude (Anthropic) — all 110 NIST 800-171 practices
"""

import os
import json
import sys
import datetime
from pathlib import Path
import anthropic
from dotenv import load_dotenv

load_dotenv()

# ── ANSI colours (no external dep beyond rich fallback) ──────────────────────
try:
    from rich.console import Console
    from rich.markdown import Markdown
    from rich.panel import Panel
    from rich.table import Table
    from rich import box
    console = Console()
    USE_RICH = True
except ImportError:
    USE_RICH = False

def cprint(text, style=""):
    if USE_RICH:
        console.print(text, style=style)
    else:
        print(text)

def print_panel(text, title="", style="blue"):
    if USE_RICH:
        console.print(Panel(text, title=title, border_style=style))
    else:
        print(f"\n=== {title} ===\n{text}\n")

# ── Control Database ─────────────────────────────────────────────────────────
CONTROLS = {
    # ── Access Control (AC) ──────────────────────────────────────────────────
    "3.1.1":  {"domain":"AC","title":"Authorized Access Control",
        "text":"Limit system access to authorized users, processes acting on behalf of authorized users, and devices (including other systems)."},
    "3.1.2":  {"domain":"AC","title":"Transaction & Function Control",
        "text":"Limit system access to the types of transactions and functions that authorized users are permitted to execute."},
    "3.1.3":  {"domain":"AC","title":"CUI Flow Control",
        "text":"Control the flow of CUI in accordance with approved authorizations."},
    "3.1.4":  {"domain":"AC","title":"Separation of Duties",
        "text":"Separate the duties of individuals to reduce the risk of malevolent activity without collusion."},
    "3.1.5":  {"domain":"AC","title":"Least Privilege",
        "text":"Employ the principle of least privilege, including for specific security functions and privileged accounts."},
    "3.1.6":  {"domain":"AC","title":"Non-Privileged Account Use",
        "text":"Use non-privileged accounts or roles when accessing non-security functions."},
    "3.1.7":  {"domain":"AC","title":"Privileged Function Restriction",
        "text":"Prevent non-privileged users from executing privileged functions and capture the execution in audit logs."},
    "3.1.8":  {"domain":"AC","title":"Unsuccessful Logon Attempts",
        "text":"Limit unsuccessful logon attempts."},
    "3.1.9":  {"domain":"AC","title":"Privacy & Security Notices",
        "text":"Provide privacy and security notices consistent with CUI rules."},
    "3.1.10": {"domain":"AC","title":"Session Lock",
        "text":"Use session lock with pattern-hiding displays after a period of inactivity."},
    "3.1.11": {"domain":"AC","title":"Session Termination",
        "text":"Terminate (automatically) a user session after a defined condition."},
    "3.1.12": {"domain":"AC","title":"Remote Access Control",
        "text":"Monitor and control remote access sessions."},
    "3.1.13": {"domain":"AC","title":"Remote Access Cryptography",
        "text":"Employ cryptographic mechanisms to protect the confidentiality of remote access sessions."},
    "3.1.14": {"domain":"AC","title":"Remote Access Routing",
        "text":"Route remote access via managed access control points."},
    "3.1.15": {"domain":"AC","title":"Remote Access Authorization",
        "text":"Authorize remote execution of privileged commands via remote access only for documented operational needs."},
    "3.1.16": {"domain":"AC","title":"Wireless Access Authorization",
        "text":"Authorize wireless access prior to allowing such connections."},
    "3.1.17": {"domain":"AC","title":"Wireless Access Protection",
        "text":"Protect wireless access using authentication and encryption."},
    "3.1.18": {"domain":"AC","title":"Mobile Device Connection",
        "text":"Control connection of mobile devices."},
    "3.1.19": {"domain":"AC","title":"CUI Encryption on Mobile",
        "text":"Encrypt CUI on mobile devices and mobile computing platforms."},
    "3.1.20": {"domain":"AC","title":"External System Connections",
        "text":"Verify and control/limit connections to external systems."},
    "3.1.21": {"domain":"AC","title":"CUI on External Systems",
        "text":"Limit use of portable storage devices on external systems."},
    "3.1.22": {"domain":"AC","title":"Public CUI Control",
        "text":"Control CUI posted or processed on publicly accessible systems."},
    # ── Awareness & Training (AT) ────────────────────────────────────────────
    "3.2.1":  {"domain":"AT","title":"Role-Based Security Awareness",
        "text":"Ensure that managers, systems administrators, and users of organizational systems are made aware of the security risks associated with their activities."},
    "3.2.2":  {"domain":"AT","title":"Security Training",
        "text":"Ensure that personnel are trained to carry out their assigned security responsibilities."},
    "3.2.3":  {"domain":"AT","title":"Insider Threat Awareness",
        "text":"Provide security awareness training on recognizing and reporting potential indicators of insider threat."},
    # ── Audit & Accountability (AU) ──────────────────────────────────────────
    "3.3.1":  {"domain":"AU","title":"System Audit Logging",
        "text":"Create and retain system audit logs and records to enable the monitoring, analysis, investigation, and reporting of unlawful or unauthorized system activity."},
    "3.3.2":  {"domain":"AU","title":"User Accountability",
        "text":"Ensure that the actions of individual system users can be traced to those users so they can be held accountable for their actions."},
    "3.3.3":  {"domain":"AU","title":"Review & Analysis of Logs",
        "text":"Review and update logged events."},
    "3.3.4":  {"domain":"AU","title":"Audit Failure Alerting",
        "text":"Alert in the event of an audit logging process failure."},
    "3.3.5":  {"domain":"AU","title":"Audit Correlation & Review",
        "text":"Correlate audit record review, analysis, and reporting processes for investigation and response."},
    "3.3.6":  {"domain":"AU","title":"Reduction & Report Generation",
        "text":"Provide audit record reduction and report generation to support on-demand analysis."},
    "3.3.7":  {"domain":"AU","title":"Authoritative Time Source",
        "text":"Provide a system capability that compares and synchronizes internal clocks with an authoritative source."},
    "3.3.8":  {"domain":"AU","title":"Audit Log Protection",
        "text":"Protect audit information and tools from unauthorized access, modification, and deletion."},
    "3.3.9":  {"domain":"AU","title":"Audit Management",
        "text":"Limit management of audit logs to a subset of privileged users."},
    # ── Configuration Management (CM) ────────────────────────────────────────
    "3.4.1":  {"domain":"CM","title":"Baseline Configuration",
        "text":"Establish and maintain baseline configurations and inventories of organizational systems."},
    "3.4.2":  {"domain":"CM","title":"Security Configuration Settings",
        "text":"Establish and enforce security configuration settings for IT products."},
    "3.4.3":  {"domain":"CM","title":"Configuration Change Control",
        "text":"Track, review, approve/disapprove, and log changes to systems."},
    "3.4.4":  {"domain":"CM","title":"Security Impact Analysis",
        "text":"Analyze the security impact of changes prior to implementation."},
    "3.4.5":  {"domain":"CM","title":"Access Restrictions for Change",
        "text":"Define, document, approve, and enforce physical and logical access restrictions associated with changes."},
    "3.4.6":  {"domain":"CM","title":"Least Functionality",
        "text":"Employ the principle of least functionality by configuring the system to provide only essential capabilities."},
    "3.4.7":  {"domain":"CM","title":"Nonessential Functionality",
        "text":"Restrict, disable, or prevent the use of nonessential programs, functions, ports, protocols, and services."},
    "3.4.8":  {"domain":"CM","title":"Application Blacklisting",
        "text":"Apply deny-by-exception policy to prevent use of unauthorized software."},
    "3.4.9":  {"domain":"CM","title":"User-Installed Software",
        "text":"Control and monitor user-installed software."},
    # ── Identification & Authentication (IA) ─────────────────────────────────
    "3.5.1":  {"domain":"IA","title":"User Identification",
        "text":"Identify system users, processes acting on behalf of users, and devices."},
    "3.5.2":  {"domain":"IA","title":"User Authentication",
        "text":"Authenticate the identities of users, processes, or devices as a prerequisite to system access."},
    "3.5.3":  {"domain":"IA","title":"Multi-Factor Authentication",
        "text":"Use multifactor authentication for local and network access to privileged accounts and network access to non-privileged accounts."},
    "3.5.4":  {"domain":"IA","title":"Replay-Resistant Authentication",
        "text":"Employ replay-resistant authentication mechanisms for network access."},
    "3.5.5":  {"domain":"IA","title":"Identifier Reuse",
        "text":"Employ identifier management practices that prevent identifier reuse for a defined period."},
    "3.5.6":  {"domain":"IA","title":"Identifier Handling",
        "text":"Disable identifiers after a defined inactivity period."},
    "3.5.7":  {"domain":"IA","title":"Password Complexity",
        "text":"Enforce a minimum password complexity and change of characters when new passwords are created."},
    "3.5.8":  {"domain":"IA","title":"Password Reuse",
        "text":"Prohibit password reuse for a specified number of generations."},
    "3.5.9":  {"domain":"IA","title":"Temporary Passwords",
        "text":"Allow temporary password use with an immediate change requirement."},
    "3.5.10": {"domain":"IA","title":"Cryptographic Password Protection",
        "text":"Store and transmit only cryptographically protected passwords."},
    "3.5.11": {"domain":"IA","title":"Obscure Feedback",
        "text":"Obscure feedback of authentication information during the authentication process."},
    # ── Incident Response (IR) ───────────────────────────────────────────────
    "3.6.1":  {"domain":"IR","title":"Incident Handling",
        "text":"Establish an operational incident-handling capability that includes preparation, detection, analysis, containment, recovery, and user response activities."},
    "3.6.2":  {"domain":"IR","title":"Incident Reporting",
        "text":"Track, document, and report incidents to appropriate officials and/or authorities."},
    "3.6.3":  {"domain":"IR","title":"Incident Response Testing",
        "text":"Test the organizational incident response capability."},
    # ── Maintenance (MA) ─────────────────────────────────────────────────────
    "3.7.1":  {"domain":"MA","title":"Controlled Maintenance",
        "text":"Perform maintenance on organizational systems."},
    "3.7.2":  {"domain":"MA","title":"Maintenance Controls",
        "text":"Provide controls on the tools, techniques, mechanisms, and personnel for system maintenance."},
    "3.7.3":  {"domain":"MA","title":"Equipment Sanitization",
        "text":"Ensure equipment removed for maintenance is sanitized."},
    "3.7.4":  {"domain":"MA","title":"Media Inspection",
        "text":"Check media containing diagnostic and test programs for malicious code before use."},
    "3.7.5":  {"domain":"MA","title":"Remote Maintenance",
        "text":"Require MFA for remote maintenance sessions and terminate after completion."},
    "3.7.6":  {"domain":"MA","title":"Maintenance Personnel",
        "text":"Supervise maintenance activities of personnel without required access authorization."},
    # ── Media Protection (MP) ────────────────────────────────────────────────
    "3.8.1":  {"domain":"MP","title":"Media Access",
        "text":"Protect system media containing CUI, both paper and digital."},
    "3.8.2":  {"domain":"MP","title":"Media Access Control",
        "text":"Limit access to CUI on system media to authorized users."},
    "3.8.3":  {"domain":"MP","title":"Media Sanitization",
        "text":"Sanitize or destroy system media before disposal or reuse."},
    "3.8.4":  {"domain":"MP","title":"Media Marking",
        "text":"Mark media with necessary CUI markings and distribution limitations."},
    "3.8.5":  {"domain":"MP","title":"Media Accountability",
        "text":"Control access to media containing CUI and maintain accountability during transport."},
    "3.8.6":  {"domain":"MP","title":"Portable Storage Encryption",
        "text":"Implement cryptographic mechanisms to protect CUI on portable storage unless protected by alternative physical safeguards."},
    "3.8.7":  {"domain":"MP","title":"Removable Media Control",
        "text":"Control the use of removable media on system components."},
    "3.8.8":  {"domain":"MP","title":"Shared Media Prohibition",
        "text":"Prohibit use of portable storage without identifiable owner."},
    "3.8.9":  {"domain":"MP","title":"CUI Backup Protection",
        "text":"Protect the confidentiality of backup CUI at storage locations."},
    # ── Personnel Security (PS) ──────────────────────────────────────────────
    "3.9.1":  {"domain":"PS","title":"Personnel Screening",
        "text":"Screen individuals prior to authorizing access to systems containing CUI."},
    "3.9.2":  {"domain":"PS","title":"Personnel Termination",
        "text":"Ensure that CUI is protected during and after personnel actions such as termination and transfer."},
    # ── Physical Protection (PE) ─────────────────────────────────────────────
    "3.10.1": {"domain":"PE","title":"Physical Access Limits",
        "text":"Limit physical access to organizational systems to authorized individuals."},
    "3.10.2": {"domain":"PE","title":"Physical Access Audit",
        "text":"Protect and monitor the physical facility and support infrastructure for systems."},
    "3.10.3": {"domain":"PE","title":"Visitor Control",
        "text":"Escort visitors and monitor visitor activity."},
    "3.10.4": {"domain":"PE","title":"Physical Access Logs",
        "text":"Maintain audit logs of physical access."},
    "3.10.5": {"domain":"PE","title":"Physical Device Protection",
        "text":"Control and manage physical access devices (keys, cards, combinations)."},
    "3.10.6": {"domain":"PE","title":"Alternative Work Sites",
        "text":"Enforce safeguarding measures for CUI at alternate work sites."},
    # ── Risk Assessment (RA) ─────────────────────────────────────────────────
    "3.11.1": {"domain":"RA","title":"Risk Assessment",
        "text":"Periodically assess the risk to operations, assets, and individuals from system operation and CUI processing."},
    "3.11.2": {"domain":"RA","title":"Vulnerability Scanning",
        "text":"Scan for vulnerabilities in systems periodically and when new vulnerabilities are identified."},
    "3.11.3": {"domain":"RA","title":"Vulnerability Remediation",
        "text":"Remediate vulnerabilities in accordance with risk assessments."},
    # ── Security Assessment (CA) ─────────────────────────────────────────────
    "3.12.1": {"domain":"CA","title":"Security Control Assessment",
        "text":"Periodically assess the security controls to determine if they are effective."},
    "3.12.2": {"domain":"CA","title":"Plan of Action",
        "text":"Develop and implement plans of action (POA&Ms) to correct deficiencies."},
    "3.12.3": {"domain":"CA","title":"Security Control Monitoring",
        "text":"Monitor security controls on an ongoing basis to ensure their effectiveness."},
    "3.12.4": {"domain":"CA","title":"System Security Plan",
        "text":"Develop, document, and periodically update system security plans."},
    # ── System & Communications Protection (SC) ──────────────────────────────
    "3.13.1": {"domain":"SC","title":"Boundary Protection",
        "text":"Monitor, control, and protect communications at external boundaries and key internal boundaries."},
    "3.13.2": {"domain":"SC","title":"Architectural Design",
        "text":"Employ architectural designs, software development techniques, and systems engineering principles promoting security."},
    "3.13.3": {"domain":"SC","title":"Security Function Isolation",
        "text":"Separate user functionality from system management functionality."},
    "3.13.4": {"domain":"SC","title":"Shared Resource Control",
        "text":"Prevent unauthorized and unintended information transfer via shared system resources."},
    "3.13.5": {"domain":"SC","title":"Public-Access System Separation",
        "text":"Implement subnetworks for publicly accessible system components that are physically or logically separated from internal networks."},
    "3.13.6": {"domain":"SC","title":"Network Communication Denial",
        "text":"Deny network communications traffic by default and allow by exception."},
    "3.13.7": {"domain":"SC","title":"Split Tunneling",
        "text":"Prevent remote devices from simultaneously connecting to the system and other resources (split tunneling)."},
    "3.13.8": {"domain":"SC","title":"Data-in-Transit Encryption",
        "text":"Implement cryptographic mechanisms to prevent unauthorized disclosure of CUI during transmission."},
    "3.13.9": {"domain":"SC","title":"Network Disconnect",
        "text":"Terminate network connections after a defined period of inactivity."},
    "3.13.10":{"domain":"SC","title":"Key Management",
        "text":"Establish and manage cryptographic keys for required cryptography in organizational systems."},
    "3.13.11":{"domain":"SC","title":"FIPS-Validated Cryptography",
        "text":"Employ FIPS-validated cryptography when used to protect the confidentiality of CUI."},
    "3.13.12":{"domain":"SC","title":"Collaborative Device Control",
        "text":"Prohibit remote activation of collaborative computing devices and provide indication of use."},
    "3.13.13":{"domain":"SC","title":"Mobile Code",
        "text":"Control and monitor the use of mobile code."},
    "3.13.14":{"domain":"SC","title":"VoIP",
        "text":"Control and monitor the use of VoIP technologies."},
    "3.13.15":{"domain":"SC","title":"Communications Authenticity",
        "text":"Protect the authenticity of communications sessions."},
    "3.13.16":{"domain":"SC","title":"Data-at-Rest Protection",
        "text":"Protect the confidentiality of CUI at rest."},
    # ── System & Information Integrity (SI) ──────────────────────────────────
    "3.14.1": {"domain":"SI","title":"Flaw Remediation",
        "text":"Identify, report, and correct system flaws in a timely manner."},
    "3.14.2": {"domain":"SI","title":"Malicious Code Protection",
        "text":"Provide protection from malicious code at appropriate locations within systems."},
    "3.14.3": {"domain":"SI","title":"Security Alerts & Advisories",
        "text":"Monitor system security alerts and advisories and take action in response."},
    "3.14.4": {"domain":"SI","title":"Malicious Code Updates",
        "text":"Update malicious code protection mechanisms when new releases are available."},
    "3.14.5": {"domain":"SI","title":"System & File Scanning",
        "text":"Perform periodic scans of systems and real-time scans of files from external sources."},
    "3.14.6": {"domain":"SI","title":"Security Monitoring",
        "text":"Monitor systems to detect attacks and indicators of potential attacks."},
    "3.14.7": {"domain":"SI","title":"Unauthorized Use Detection",
        "text":"Identify unauthorized use of organizational systems."},
}

DOMAIN_NAMES = {
    "AC": "Access Control",
    "AT": "Awareness & Training",
    "AU": "Audit & Accountability",
    "CM": "Configuration Management",
    "IA": "Identification & Authentication",
    "IR": "Incident Response",
    "MA": "Maintenance",
    "MP": "Media Protection",
    "PS": "Personnel Security",
    "PE": "Physical Protection",
    "RA": "Risk Assessment",
    "CA": "Security Assessment (CA&A)",
    "SC": "System & Comms Protection",
    "SI": "System & Information Integrity",
}

STATUS_FILE = Path(__file__).parent / "status.json"

def load_status():
    if STATUS_FILE.exists():
        return json.loads(STATUS_FILE.read_text())
    return {}

def save_status(status):
    STATUS_FILE.write_text(json.dumps(status, indent=2))

# ── Tool implementations ──────────────────────────────────────────────────────

def tool_check_control(control_id: str, status: dict) -> str:
    c = CONTROLS.get(control_id)
    if not c:
        return f"Control {control_id} not found. Valid IDs are e.g. 3.1.1, 3.5.3, 3.13.8"
    s = status.get(control_id, {})
    impl = s.get("status", "not_assessed")
    notes = s.get("notes", "No notes recorded.")
    result = {
        "control_id": control_id,
        "domain": f"{c['domain']} — {DOMAIN_NAMES[c['domain']]}",
        "title": c["title"],
        "requirement": c["text"],
        "implementation_status": impl,
        "notes": notes,
        "last_updated": s.get("updated", "Never"),
    }
    return json.dumps(result, indent=2)

def tool_list_gaps(domain: str, status: dict) -> str:
    domain = domain.upper()
    if domain not in DOMAIN_NAMES and domain != "ALL":
        return f"Unknown domain '{domain}'. Use AC, AT, AU, CM, IA, IR, MA, MP, PS, PE, RA, CA, SC, SI, or ALL."
    gaps = []
    for cid, ctrl in CONTROLS.items():
        if domain != "ALL" and ctrl["domain"] != domain:
            continue
        s = status.get(cid, {}).get("status", "not_assessed")
        if s not in ("implemented",):
            gaps.append({
                "control_id": cid,
                "domain": ctrl["domain"],
                "title": ctrl["title"],
                "status": s,
            })
    if not gaps:
        return f"No gaps found in domain {domain}. All controls marked implemented."
    return json.dumps(gaps, indent=2)

def tool_score_program(status: dict) -> str:
    scores = {}
    total_impl = 0
    for abbr in DOMAIN_NAMES:
        domain_controls = [cid for cid, c in CONTROLS.items() if c["domain"] == abbr]
        impl = sum(1 for cid in domain_controls if status.get(cid, {}).get("status") == "implemented")
        partial = sum(1 for cid in domain_controls if status.get(cid, {}).get("status") == "partial")
        total = len(domain_controls)
        score = round((impl + partial * 0.5) / total * 100, 1) if total else 0
        scores[abbr] = {"domain": DOMAIN_NAMES[abbr], "implemented": impl, "partial": partial,
                        "total": total, "score_pct": score}
        total_impl += impl + partial * 0.5
    overall = round(total_impl / len(CONTROLS) * 100, 1)
    return json.dumps({"overall_score_pct": overall, "domains": scores}, indent=2)

def tool_generate_poam(control_id: str, weakness: str, status: dict) -> str:
    c = CONTROLS.get(control_id)
    if not c:
        return f"Control {control_id} not found."
    today = datetime.date.today().isoformat()
    due = (datetime.date.today() + datetime.timedelta(days=90)).isoformat()
    poam = {
        "poam_id": f"POAM-{control_id.replace('.', '-')}",
        "control_id": control_id,
        "control_title": c["title"],
        "domain": DOMAIN_NAMES[c["domain"]],
        "weakness_description": weakness,
        "recommended_corrective_action": (
            f"Implement {c['title']} per NIST 800-171 requirement: {c['text']}"
        ),
        "responsible_entity": "System Owner / ISSO",
        "resources_required": "TBD — assess budget and staffing",
        "scheduled_completion_date": due,
        "milestone_with_completion_date": [
            {"milestone": "Assign responsible party", "date": today},
            {"milestone": "Define corrective action plan", "date": (datetime.date.today() + datetime.timedelta(days=14)).isoformat()},
            {"milestone": "Implement corrective action", "date": (datetime.date.today() + datetime.timedelta(days=60)).isoformat()},
            {"milestone": "Verify and close POA&M", "date": due},
        ],
        "status": "Open",
        "identified_date": today,
    }
    return json.dumps(poam, indent=2)

def tool_mark_control(control_id: str, impl_status: str, notes: str, status: dict) -> str:
    valid = ("implemented", "partial", "not_implemented", "not_assessed")
    if impl_status not in valid:
        return f"Invalid status '{impl_status}'. Use: {', '.join(valid)}"
    if control_id not in CONTROLS:
        return f"Control {control_id} not found."
    status[control_id] = {
        "status": impl_status,
        "notes": notes,
        "updated": datetime.date.today().isoformat(),
    }
    save_status(status)
    return f"Control {control_id} marked as '{impl_status}'. Status saved."

def tool_search_controls(query: str) -> str:
    q = query.lower()
    matches = []
    for cid, ctrl in CONTROLS.items():
        if q in ctrl["title"].lower() or q in ctrl["text"].lower() or q in ctrl["domain"].lower():
            matches.append({"control_id": cid, "domain": ctrl["domain"],
                           "title": ctrl["title"]})
    if not matches:
        return f"No controls matched '{query}'."
    return json.dumps(matches, indent=2)

def tool_list_domains() -> str:
    result = []
    for abbr, name in DOMAIN_NAMES.items():
        count = sum(1 for c in CONTROLS.values() if c["domain"] == abbr)
        result.append({"abbreviation": abbr, "name": name, "control_count": count})
    return json.dumps(result, indent=2)

# ── Tool schemas for Claude ───────────────────────────────────────────────────
TOOLS = [
    {
        "name": "check_control",
        "description": "Look up a specific CMMC/NIST 800-171 control by ID. Returns the requirement text, implementation status, and any notes.",
        "input_schema": {
            "type": "object",
            "properties": {
                "control_id": {"type": "string", "description": "NIST 800-171 control ID e.g. '3.1.1', '3.5.3', '3.13.8'"}
            },
            "required": ["control_id"]
        }
    },
    {
        "name": "list_gaps",
        "description": "List all controls that are not fully implemented in a given domain or across all domains. Returns controls with status 'not_implemented', 'partial', or 'not_assessed'.",
        "input_schema": {
            "type": "object",
            "properties": {
                "domain": {"type": "string", "description": "Domain abbreviation: AC, AT, AU, CM, IA, IR, MA, MP, PS, PE, RA, CA, SC, SI — or ALL for all domains"}
            },
            "required": ["domain"]
        }
    },
    {
        "name": "score_program",
        "description": "Calculate the overall CMMC compliance score and per-domain breakdown based on current implementation status.",
        "input_schema": {"type": "object", "properties": {}, "required": []}
    },
    {
        "name": "generate_poam",
        "description": "Generate a Plan of Action and Milestones (POA&M) entry for a specific control gap.",
        "input_schema": {
            "type": "object",
            "properties": {
                "control_id": {"type": "string", "description": "The control ID with a gap, e.g. '3.5.3'"},
                "weakness": {"type": "string", "description": "Description of the specific weakness or gap identified"}
            },
            "required": ["control_id", "weakness"]
        }
    },
    {
        "name": "mark_control",
        "description": "Record the implementation status of a control. Use this when the user reports their implementation state.",
        "input_schema": {
            "type": "object",
            "properties": {
                "control_id": {"type": "string", "description": "e.g. '3.5.3'"},
                "impl_status": {"type": "string", "enum": ["implemented", "partial", "not_implemented", "not_assessed"],
                                "description": "Current implementation state"},
                "notes": {"type": "string", "description": "Implementation details, evidence location, or notes"}
            },
            "required": ["control_id", "impl_status", "notes"]
        }
    },
    {
        "name": "search_controls",
        "description": "Search controls by keyword — e.g. 'encryption', 'MFA', 'audit', 'wireless', 'CUI'.",
        "input_schema": {
            "type": "object",
            "properties": {
                "query": {"type": "string", "description": "Search term"}
            },
            "required": ["query"]
        }
    },
    {
        "name": "list_domains",
        "description": "List all 14 CMMC 2.0 Level 2 domains with their abbreviations and control counts.",
        "input_schema": {"type": "object", "properties": {}, "required": []}
    },
]

SYSTEM_PROMPT = """You are an expert CMMC 2.0 Level 2 compliance agent with deep knowledge of NIST SP 800-171, DFARS 7012, and the DoD Assessment Methodology.

You help organizations assess, track, and improve their CMMC compliance posture. You have access to all 110 NIST 800-171 practices mapped to CMMC Level 2.

Your capabilities:
- Check any control's requirement text and current implementation status
- Identify compliance gaps by domain or across the full control set
- Score the program and show per-domain breakdowns
- Generate properly structured POA&M entries
- Record implementation status and notes
- Search controls by keyword (encryption, MFA, audit, etc.)

Guidelines:
- Always use tools to look up real control data rather than relying on memory alone
- When a user describes their environment, use mark_control to record status
- Be precise with control IDs (format: 3.x.x)
- Flag high-risk gaps (IA, AC, AU, SC domains carry highest weight in assessments)
- When generating POA&Ms, be specific and actionable
- Reference the DoD CMMC Assessment Guide and NIST 800-171A for evidence guidance when relevant

Tone: Direct, technically precise, practitioner-level. No fluff."""

# ── Main agent loop ───────────────────────────────────────────────────────────
def run_agent():
    api_key = os.environ.get("ANTHROPIC_API_KEY")
    if not api_key:
        print("ERROR: ANTHROPIC_API_KEY not set. Copy .env.example to .env and add your key.")
        sys.exit(1)

    client = anthropic.Anthropic(api_key=api_key)
    status = load_status()
    messages = []

    if USE_RICH:
        console.print(Panel(
            "[bold cyan]CMMC 2.0 Level 2 Compliance Agent[/bold cyan]\n"
            "[dim]110 NIST 800-171 practices · Gap analysis · POA&M generation · Scoring[/dim]\n\n"
            "[yellow]Commands:[/yellow] ask anything, or try:\n"
            "  • [green]score my program[/green]\n"
            "  • [green]what are my gaps in AC[/green]\n"
            "  • [green]check control 3.5.3[/green]\n"
            "  • [green]generate a POA&M for 3.13.8[/green]\n"
            "  • [green]mark 3.5.3 as implemented — using Okta MFA[/green]\n"
            "  • [green]search controls for encryption[/green]\n"
            "  • [dim]exit[/dim] to quit",
            title="[bold]Welcome[/bold]",
            border_style="cyan"
        ))
    else:
        print("\nCMMC 2.0 Level 2 Compliance Agent")
        print("110 NIST 800-171 practices | Gap analysis | POA&M generation")
        print("Type 'exit' to quit.\n")

    while True:
        try:
            if USE_RICH:
                console.print("\n[bold green]You>[/bold green] ", end="")
                user_input = input().strip()
            else:
                user_input = input("\nYou> ").strip()
        except (EOFError, KeyboardInterrupt):
            print("\nExiting.")
            break

        if not user_input:
            continue
        if user_input.lower() in ("exit", "quit", "q"):
            print("Goodbye.")
            break

        messages.append({"role": "user", "content": user_input})

        # Agentic tool-use loop
        while True:
            response = client.messages.create(
                model="claude-opus-4-5",
                max_tokens=4096,
                system=SYSTEM_PROMPT,
                tools=TOOLS,
                messages=messages,
            )

            # Collect assistant content
            messages.append({"role": "assistant", "content": response.content})

            if response.stop_reason == "end_turn":
                # Print final response
                for block in response.content:
                    if hasattr(block, "text"):
                        if USE_RICH:
                            console.print("\n[bold blue]Agent>[/bold blue]")
                            console.print(Markdown(block.text))
                        else:
                            print(f"\nAgent> {block.text}")
                break

            if response.stop_reason == "tool_use":
                tool_results = []
                for block in response.content:
                    if block.type != "tool_use":
                        continue

                    name   = block.name
                    inputs = block.input

                    if USE_RICH:
                        console.print(f"[dim]  → {name}({', '.join(f'{k}={v!r}' for k,v in inputs.items())})[/dim]")

                    # Dispatch
                    if name == "check_control":
                        result = tool_check_control(inputs["control_id"], status)
                    elif name == "list_gaps":
                        result = tool_list_gaps(inputs["domain"], status)
                    elif name == "score_program":
                        result = tool_score_program(status)
                    elif name == "generate_poam":
                        result = tool_generate_poam(inputs["control_id"], inputs["weakness"], status)
                    elif name == "mark_control":
                        result = tool_mark_control(inputs["control_id"], inputs["impl_status"], inputs["notes"], status)
                    elif name == "search_controls":
                        result = tool_search_controls(inputs["query"])
                    elif name == "list_domains":
                        result = tool_list_domains()
                    else:
                        result = f"Unknown tool: {name}"

                    tool_results.append({
                        "type": "tool_result",
                        "tool_use_id": block.id,
                        "content": result,
                    })

                messages.append({"role": "user", "content": tool_results})
                # Continue loop for Claude to process results
            else:
                break  # unexpected stop reason

if __name__ == "__main__":
    run_agent()
