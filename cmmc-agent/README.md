# CMMC 2.0 Level 2 Compliance Agent

A Claude-powered CLI agent that helps you assess, track, and close gaps across all 110 NIST 800-171 practices required for CMMC Level 2 certification.

## Features

- **Full control database** — all 110 NIST 800-171 practices with requirement text
- **Gap analysis** — per-domain or full-program gap reports
- **Compliance scoring** — overall % and per-domain breakdowns
- **POA&M generation** — structured Plan of Action & Milestones entries
- **Status tracking** — mark controls as implemented/partial/not implemented, saved locally
- **Keyword search** — find controls by topic (encryption, MFA, audit, CUI, etc.)
- **Agentic tool use** — Claude calls tools automatically based on your questions

## Setup

```bash
cd cmmc-agent

# Create and activate a virtual environment
python3 -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate

# Install dependencies
pip install -r requirements.txt

# Add your Anthropic API key
cp .env.example .env
# Edit .env and set ANTHROPIC_API_KEY=sk-ant-...
```

## Running

### Web GUI (recommended)
```bash
python server.py
# Open http://localhost:5050
```
Features: chat interface, live compliance score ring, per-domain progress bars,
POA&M display, and a **?** help button with full usage instructions.

### CLI
```bash
python agent.py
```

## Example Prompts

```
score my program
what are my gaps in the IA domain
check control 3.5.3
search controls for encryption
mark 3.5.3 as implemented — using Okta MFA with FIDO2 hardware tokens
generate a POA&M for 3.13.8 — CUI is transmitted over unencrypted internal links
what controls cover CUI at rest
list all domains
show me all gaps across every domain
```

## How Status Is Saved

Implementation status is stored in `status.json` (gitignored). Each entry records:
- `status`: `implemented` | `partial` | `not_implemented` | `not_assessed`
- `notes`: your implementation details or evidence location
- `updated`: date last changed

## Scoring

| Score  | Meaning                        |
|--------|--------------------------------|
| 90–100 | Assessment-ready               |
| 70–89  | Moderate gaps, manageable POA&Ms |
| 50–69  | Significant remediation needed |
| < 50   | High risk, major gaps          |

Partial implementations count as 50% toward the score.

## CMMC Domains Covered

| Code | Domain                          | Controls |
|------|---------------------------------|----------|
| AC   | Access Control                  | 22       |
| AT   | Awareness & Training            | 3        |
| AU   | Audit & Accountability          | 9        |
| CM   | Configuration Management        | 9        |
| IA   | Identification & Authentication | 11       |
| IR   | Incident Response               | 3        |
| MA   | Maintenance                     | 6        |
| MP   | Media Protection                | 9        |
| PS   | Personnel Security              | 2        |
| PE   | Physical Protection             | 6        |
| RA   | Risk Assessment                 | 3        |
| CA   | Security Assessment             | 4        |
| SC   | System & Comms Protection       | 16       |
| SI   | System & Information Integrity  | 7        |
