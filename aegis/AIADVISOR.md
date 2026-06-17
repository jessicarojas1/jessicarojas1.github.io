# AEGIS — AIAdvisor

AIAdvisor provides **opt-in**, **assistive** AI features: control-gap remediation
suggestions and executive compliance-posture narratives. It is advisory only —
it never writes records. A human reviews and acts on every output.

## Principles (ISO 42001 / NIST AI RMF aligned)

1. **Opt-in.** Disabled unless an admin configures a provider + API key.
2. **Human oversight.** Output is suggestion-only; AIAdvisor makes no record
   changes. Every surface that shows AI output also shows `AIAdvisor::DISCLAIMER`.
3. **Global kill-switch.** An admin can disable all AI features org-wide,
   regardless of key configuration.
4. **Data minimization.** Only the data needed for the task is sent, and it is
   passed through a redaction pass first.
5. **Traceability.** AI use is recorded in the tamper-evident audit trail.

## Configuration

Stored in the `settings` table:

| Key | Meaning |
|-----|---------|
| `ai_settings` (JSON) or `ai_provider` + `ai_api_key` | Provider (`claude` \| `openai`) and the **encrypted** API key. |
| `ai_enabled` | Global kill-switch. `1` = enabled (default), `0`/`false`/`off`/`no` = disabled. |

API keys are encrypted at rest with `Security::encryptSetting()`.

### Enabling / disabling

```sql
-- Disable all AI features org-wide:
UPDATE settings SET value = '0' WHERE key = 'ai_enabled';
-- Re-enable:
UPDATE settings SET value = '1' WHERE key = 'ai_enabled';
```

Resolution: `AIAdvisor::isEnabled()` returns true only when a provider **and**
key are configured **and** `globallyEnabled()` is true.

## Redaction

Before any text leaves the organization, `AIAdvisor::redact()` strips obvious
secrets/PII that might appear in control titles or descriptions:

| Pattern | Replacement |
|---------|-------------|
| Email addresses | `[redacted-email]` |
| IPv4 addresses | `[redacted-ip]` |
| `sk-`/`pk-`/`rk-` keys, `AKIA…` access keys | `[redacted-key]` |
| `Bearer <token>` | `Bearer [redacted-token]` |
| 32+ char hex strings | `[redacted-secret]` |

`redact()` is a pure function covered by `tests/test_aiadvisor.php`. It is applied
to the control list and standard name in both prompt builders.

> Redaction is best-effort defense-in-depth, not a guarantee. Treat any
> configured AI provider as a third-party data processor and disclose it in your
> records of processing.

## Endpoints & models

Fixed, hard-coded vendor endpoints (no user-supplied URLs, so no SSRF surface):

- Claude: `https://api.anthropic.com/v1/messages` (`claude-haiku-4-5-20251001`)
- OpenAI: `https://api.openai.com/v1/chat/completions` (`gpt-4o-mini`)

When building new AI features, default to the latest and most capable Claude
models.

## Audit & logging

- **Audit trail:** `Auth::log('ai.gap_analysis', 'compliance_package', $id)` is
  recorded when gap analysis runs — actor, action, entity, IP, timestamp, hashed
  into the chain (see `AUDIT_TRAIL.md`).
- **Inference log:** `logInference()` records provider, model, latency, token
  estimate, and success/error for operational visibility.

## Extending

- Keep new features **read-only**; never let AI mutate records without explicit
  user confirmation in the UI.
- Always present `AIAdvisor::DISCLAIMER` alongside AI output.
- Run untrusted free-text through `redact()` before sending it to a provider.
- Gate every entry point on `AIAdvisor::isEnabled()`.
