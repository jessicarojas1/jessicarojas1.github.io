-- ─────────────────────────────────────────────────────────────────────────────
-- 006 Email management & risk review features
-- ─────────────────────────────────────────────────────────────────────────────

-- ── 1. Email templates ────────────────────────────────────────────────────────
-- Per-type HTML email templates with {{variable}} placeholder substitution.

CREATE TABLE IF NOT EXISTS email_templates (
    id          SERIAL PRIMARY KEY,
    type        VARCHAR(100) NOT NULL UNIQUE,   -- e.g. 'overdue_controls', 'risk_review_due'
    name        VARCHAR(255) NOT NULL,
    subject     VARCHAR(500) NOT NULL,          -- supports {{variables}}
    body_html   TEXT        NOT NULL,           -- HTML with {{variable}} placeholders
    body_text   TEXT,                           -- plain-text fallback
    variables   JSONB       NOT NULL DEFAULT '[]', -- array of variable names used
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    updated_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── 2. Report schedules ───────────────────────────────────────────────────────
-- Configuration for scheduled email reports (risk register, compliance, etc.).

CREATE TABLE IF NOT EXISTS report_schedules (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    report_type     VARCHAR(100) NOT NULL,   -- 'risk_register','compliance_summary','audit_status','executive_summary'
    frequency       VARCHAR(50)  NOT NULL DEFAULT 'weekly'
                    CHECK (frequency IN ('daily','weekly','monthly','quarterly')),
    day_of_week     INTEGER DEFAULT 1,       -- 0=Sunday … 6=Saturday (for weekly)
    day_of_month    INTEGER DEFAULT 1,       -- 1-28 (for monthly/quarterly)
    send_time       TIME        NOT NULL DEFAULT '08:00',
    recipients      JSONB       NOT NULL DEFAULT '[]',   -- array of email addresses
    filters         JSONB       NOT NULL DEFAULT '{}',   -- e.g. {"status":"open","category_id":5}
    format          VARCHAR(10) NOT NULL DEFAULT 'html'
                    CHECK (format IN ('html','csv','both')),
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    last_sent_at    TIMESTAMP,
    next_send_at    TIMESTAMP,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── 3. Email verification tokens ─────────────────────────────────────────────
-- Used to verify new user email addresses before granting full access.

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    expires_at  TIMESTAMP   NOT NULL,
    used_at     TIMESTAMP,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evt_user ON email_verification_tokens(user_id);

-- ── 4. Email bounces ──────────────────────────────────────────────────────────
-- Records bounced or undeliverable email addresses.

CREATE TABLE IF NOT EXISTS email_bounces (
    id          SERIAL PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    bounce_type VARCHAR(50)  NOT NULL DEFAULT 'hard'
                CHECK (bounce_type IN ('hard','soft','complaint')),
    reason      TEXT,
    recorded_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eb_email ON email_bounces(email);
-- Enforce a single hard-bounce record per address (idempotent suppression list)
CREATE UNIQUE INDEX IF NOT EXISTS idx_eb_email_hard ON email_bounces(email)
    WHERE bounce_type = 'hard';

-- ── 5. Email unsubscribes ─────────────────────────────────────────────────────
-- Stores unsubscribe tokens and records; NULL notification_type means "all".

CREATE TABLE IF NOT EXISTS email_unsubscribes (
    id                  SERIAL PRIMARY KEY,
    user_id             INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email               VARCHAR(255) NOT NULL,
    token               VARCHAR(64)  NOT NULL UNIQUE,
    notification_type   VARCHAR(100),   -- NULL = unsubscribe from everything
    unsubscribed_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eu_email ON email_unsubscribes(email);
CREATE INDEX IF NOT EXISTS idx_eu_token ON email_unsubscribes(token);

-- ── 6. Risk reviews ───────────────────────────────────────────────────────────
-- Formal periodic risk review sessions (periodic, triggered, ad-hoc, board).

CREATE TABLE IF NOT EXISTS risk_reviews (
    id                  SERIAL PRIMARY KEY,
    title               VARCHAR(500) NOT NULL,
    review_type         VARCHAR(50)  NOT NULL DEFAULT 'periodic'
                        CHECK (review_type IN ('periodic','triggered','ad_hoc','board')),
    scheduled_date      DATE         NOT NULL,
    completed_date      DATE,
    status              VARCHAR(30)  NOT NULL DEFAULT 'planned'
                        CHECK (status IN ('planned','in_progress','completed','cancelled')),
    lead_reviewer_id    INTEGER REFERENCES users(id),
    scope_description   TEXT,
    scope_filter        JSONB        NOT NULL DEFAULT '{}',  -- {category_id, owner_id, min_score, status}
    total_risks         INTEGER      NOT NULL DEFAULT 0,
    reviewed_count      INTEGER      NOT NULL DEFAULT 0,
    escalated_count     INTEGER      NOT NULL DEFAULT 0,
    conclusion          TEXT,
    sign_off_by         INTEGER REFERENCES users(id),
    sign_off_at         TIMESTAMP,
    sign_off_notes      TEXT,
    created_by          INTEGER REFERENCES users(id),
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rr_status    ON risk_reviews(status);
CREATE INDEX IF NOT EXISTS idx_rr_scheduled ON risk_reviews(scheduled_date);

-- ── 7. Risk review items ──────────────────────────────────────────────────────
-- Individual risk assessments within a review session.

CREATE TABLE IF NOT EXISTS risk_review_items (
    id                  SERIAL PRIMARY KEY,
    review_id           INTEGER      NOT NULL REFERENCES risk_reviews(id) ON DELETE CASCADE,
    risk_id             INTEGER      NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    status              VARCHAR(30)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','reviewed','escalated','deferred','not_applicable')),
    score_confirmed     BOOLEAN,
    new_likelihood      INTEGER CHECK (new_likelihood BETWEEN 1 AND 5),
    new_impact          INTEGER CHECK (new_impact BETWEEN 1 AND 5),
    treatment_adequate  BOOLEAN,
    action_required     TEXT,
    reviewer_notes      TEXT,
    reviewed_by         INTEGER REFERENCES users(id),
    reviewed_at         TIMESTAMP,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_rri_review ON risk_review_items(review_id);
CREATE INDEX IF NOT EXISTS idx_rri_risk   ON risk_review_items(risk_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- ALTER TABLE additions
-- ─────────────────────────────────────────────────────────────────────────────

-- NULL = not yet verified; populated when user clicks the verification link.
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP;

-- Digest mode: receive notifications immediately, or batched daily/weekly.
ALTER TABLE user_notification_prefs
    ADD COLUMN IF NOT EXISTS digest_mode VARCHAR(20) NOT NULL DEFAULT 'immediate'
    CHECK (digest_mode IN ('immediate','daily','weekly'));

ALTER TABLE user_notification_prefs
    ADD COLUMN IF NOT EXISTS digest_time TIME DEFAULT '08:00';

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed: default email templates
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO email_templates (type, name, subject, body_html, body_text, variables)
VALUES

-- 1. Overdue Controls Alert
(
    'overdue_controls',
    'Overdue Controls Alert',
    '[AEGIS] {{count}} compliance control(s) require your attention',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <!-- Header -->
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Compliance &amp; Risk Management Platform</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Hi {{user_name}},</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              You have <strong style="color:#6366f1">{{count}} compliance control(s)</strong> that are overdue and require your attention.
            </p>
            {{controls_list}}
            <p style="margin:24px 0 0;font-size:14px;color:#6b7280;line-height:1.6">
              Please review and update the status of each control as soon as possible to maintain your compliance posture.
            </p>
          </td>
        </tr>
        <!-- CTA -->
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{app_url}}/compliance" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              View Overdue Controls &rarr;
            </a>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated compliance notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Hi {{user_name}},

You have {{count}} compliance control(s) that are overdue and require your attention.

{{controls_list}}

Please log in to AEGIS GRC to review and update each control.

-- AEGIS GRC (automated notification)',
    '["count","controls_list","user_name"]'
),

-- 2. Policy Review Due
(
    'policy_review_due',
    'Policy Review Due',
    '[AEGIS] Policy review due: {{policy_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Policy Management</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Policy Review Required</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              The following policy is scheduled for review and requires your action.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:0">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Policy</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{policy_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Review Due Date</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#dc2626;font-weight:600">{{review_date}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{policy_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              Review Policy &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated policy notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Policy Review Required

Policy: {{policy_title}}
Review Due: {{review_date}}

Please review this policy at: {{policy_url}}

-- AEGIS GRC (automated notification)',
    '["policy_title","review_date","policy_url"]'
),

-- 3. Pending Approval Reminder
(
    'pending_approval',
    'Pending Approval Reminder',
    '[AEGIS] Approval pending: {{item_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Approval Workflow</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Action Required: Pending Approval</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              An item has been submitted and is awaiting your approval.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Item</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{item_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Submitted By</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#374151">{{submitted_by}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Submitted At</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#374151">{{submitted_at}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{action_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              Review &amp; Approve &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated approval notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Pending Approval: {{item_title}}

Submitted by: {{submitted_by}}
Submitted at: {{submitted_at}}

Review and approve at: {{action_url}}

-- AEGIS GRC (automated notification)',
    '["item_title","submitted_by","submitted_at","action_url"]'
),

-- 4. New Risk Assigned
(
    'risk_assigned',
    'New Risk Assigned',
    '[AEGIS] New risk assigned to you: {{risk_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Risk Management</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">New Risk Assigned to You</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              Hi {{owner_name}}, a new risk has been assigned to you as the risk owner.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Risk</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{risk_title}}</p>
                  <p style="margin:2px 0 0;font-size:13px;color:#6b7280">ID: {{risk_id}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Risk Score</p>
                  <p style="margin:4px 0 0;font-size:22px;font-weight:700;color:#dc2626">{{score}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{risk_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              View Risk &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated risk notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'New Risk Assigned: {{risk_title}} (ID: {{risk_id}})

Risk Score: {{score}}
Assigned to: {{owner_name}}

View the risk at: {{risk_url}}

-- AEGIS GRC (automated notification)',
    '["risk_title","risk_id","score","owner_name","risk_url"]'
),

-- 5. Open Incident Aging
(
    'incident_aging',
    'Open Incident Aging',
    '[AEGIS] Incident still open: {{incident_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Incident Management</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Aging Incident Reminder</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              The following incident has been open for an extended period and may require escalation.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #fed7aa">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.5px">Incident</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{incident_title}}</p>
                  <p style="margin:2px 0 0;font-size:13px;color:#6b7280">{{incident_number}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #fed7aa">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.5px">Opened At</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#374151">{{opened_at}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.5px">Time Open</p>
                  <p style="margin:4px 0 0;font-size:22px;font-weight:700;color:#ea580c">{{age_hours}} hours</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{app_url}}/incidents" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              View Incident &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated incident notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Aging Incident: {{incident_title}} ({{incident_number}})

Opened at: {{opened_at}}
Time open: {{age_hours}} hours

This incident requires attention. Log in to AEGIS GRC to review and update.

-- AEGIS GRC (automated notification)',
    '["incident_title","incident_number","opened_at","age_hours"]'
),

-- 6. Risk Review Overdue
(
    'risk_review_overdue',
    'Risk Review Overdue',
    '[AEGIS] {{count}} risk(s) overdue for review',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Risk Management</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Hi {{user_name}},</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              You have <strong style="color:#dc2626">{{count}} risk(s)</strong> that are past their scheduled review date and require your attention.
            </p>
            {{risks_list}}
            <p style="margin:24px 0 0;font-size:14px;color:#6b7280;line-height:1.6">
              Please review each risk and update the review date or status accordingly.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{app_url}}/risks" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              View Risk Register &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated risk notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Hi {{user_name}},

You have {{count}} risk(s) overdue for review.

{{risks_list}}

Log in to AEGIS GRC to review these risks.

-- AEGIS GRC (automated notification)',
    '["count","risks_list","user_name"]'
),

-- 7. Treatment Plan Due
(
    'treatment_due',
    'Treatment Plan Due',
    '[AEGIS] Risk treatment due: {{treatment_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Risk Treatment Tracking</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Risk Treatment Due Soon</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              A risk treatment action plan is approaching or has reached its due date.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Treatment</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{treatment_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Associated Risk</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#374151">{{risk_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Due Date</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#dc2626;font-weight:600">{{due_date}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{risk_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              View Risk &amp; Treatment &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated treatment notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Risk Treatment Due: {{treatment_title}}

Associated Risk: {{risk_title}}
Due Date: {{due_date}}

View the risk and treatment plan at: {{risk_url}}

-- AEGIS GRC (automated notification)',
    '["treatment_title","risk_title","due_date","risk_url"]'
),

-- 8. Risk Score Increased
(
    'risk_score_worsened',
    'Risk Score Increased',
    '[AEGIS] Risk score increased: {{risk_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Risk Management Alert</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Risk Score Has Increased</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              The inherent risk score for the following risk has increased and may require immediate attention.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #fecaca">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#991b1b;text-transform:uppercase;letter-spacing:.5px">Risk</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{risk_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td width="50%" style="padding-right:8px">
                        <p style="margin:0;font-size:12px;font-weight:600;color:#991b1b;text-transform:uppercase;letter-spacing:.5px">Previous Score</p>
                        <p style="margin:6px 0 0;font-size:28px;font-weight:700;color:#6b7280">{{old_score}}</p>
                      </td>
                      <td width="50%" style="padding-left:8px;border-left:1px solid #fecaca">
                        <p style="margin:0;font-size:12px;font-weight:600;color:#991b1b;text-transform:uppercase;letter-spacing:.5px">New Score</p>
                        <p style="margin:6px 0 0;font-size:28px;font-weight:700;color:#dc2626">{{new_score}}</p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{risk_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              Review Risk &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated risk alert &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Risk Score Increased: {{risk_title}}

Previous Score: {{old_score}}
New Score:      {{new_score}}

Review the risk at: {{risk_url}}

-- AEGIS GRC (automated notification)',
    '["risk_title","old_score","new_score","risk_url"]'
),

-- 9. Risk Review Session Due
(
    'risk_review_session_due',
    'Risk Review Session Due',
    '[AEGIS] Risk review session due: {{review_title}}',
    '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0">
    <tr><td align="center">
      <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#6366f1;padding:28px 32px">
            <p style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:.5px">AEGIS GRC</p>
            <p style="margin:4px 0 0;font-size:13px;color:#c7d2fe;opacity:.9">Risk Review Programme</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px">
            <h2 style="margin:0 0 8px;font-size:20px;color:#1f2937">Risk Review Session Approaching</h2>
            <p style="margin:0 0 20px;font-size:15px;color:#4b5563;line-height:1.6">
              A scheduled risk review session is due. Please prepare the required materials and ensure all assigned risks are ready for review.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px">
              <tr>
                <td style="padding:16px 20px;border-bottom:1px solid #e5e7eb">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Review Session</p>
                  <p style="margin:4px 0 0;font-size:16px;font-weight:600;color:#1f2937">{{review_title}}</p>
                </td>
              </tr>
              <tr>
                <td style="padding:16px 20px">
                  <p style="margin:0;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Scheduled Date</p>
                  <p style="margin:4px 0 0;font-size:15px;color:#6366f1;font-weight:600">{{scheduled_date}}</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:0 32px 32px">
            <a href="{{review_url}}" style="display:inline-block;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 28px;border-radius:6px">
              Open Review Session &rarr;
            </a>
          </td>
        </tr>
        <tr>
          <td style="padding:20px 32px;border-top:1px solid #e5e7eb;background:#f9fafb">
            <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
              AEGIS GRC &mdash; Automated review notification &mdash; Do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>',
    'Risk Review Session Due: {{review_title}}

Scheduled Date: {{scheduled_date}}

Open the review session at: {{review_url}}

-- AEGIS GRC (automated notification)',
    '["review_title","scheduled_date","review_url"]'
)

ON CONFLICT (type) DO NOTHING;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed: sample report schedules
-- is_active = FALSE because recipients are not yet configured.
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO report_schedules
    (name, report_type, frequency, day_of_week, day_of_month, send_time, recipients, filters, format, is_active)
VALUES
    -- Weekly Risk Summary: every Monday at 08:00
    (
        'Weekly Risk Summary',
        'risk_register',
        'weekly',
        1,        -- Monday
        1,
        '08:00',
        '[]',
        '{}',
        'html',
        FALSE
    ),
    -- Monthly Compliance Report: 1st of each month at 08:00
    (
        'Monthly Compliance Report',
        'compliance_summary',
        'monthly',
        1,
        1,        -- day 1
        '08:00',
        '[]',
        '{}',
        'html',
        FALSE
    ),
    -- Quarterly Executive Summary: 1st of each quarter at 08:00
    (
        'Quarterly Executive Summary',
        'executive_summary',
        'quarterly',
        1,
        1,        -- day 1
        '08:00',
        '[]',
        '{}',
        'html',
        FALSE
    )
;
