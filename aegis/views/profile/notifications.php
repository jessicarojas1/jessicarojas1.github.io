<?php
/**
 * User notification preferences — self-posting page.
 * Route: GET /profile/notifications
 *       POST /profile/notifications/save  (handled at top of this file)
 */

$NOTIF_TYPES = [
    'overdue_controls'    => [
        'label'       => 'Overdue controls',
        'description' => 'When a compliance control you own is past its due date',
        'icon'        => 'shield-exclamation',
    ],
    'policy_review_due'   => [
        'label'       => 'Policy review due',
        'description' => 'When a policy you own is due for review within 7 days',
        'icon'        => 'file-earmark-text-fill',
    ],
    'pending_approval'    => [
        'label'       => 'Pending approvals',
        'description' => 'Reminder when an approval request is waiting for you',
        'icon'        => 'check2-square',
    ],
    'new_risk_assigned'   => [
        'label'       => 'Risk assignments',
        'description' => 'When a new risk is assigned to you',
        'icon'        => 'exclamation-triangle-fill',
    ],
    'open_incident_aging' => [
        'label'       => 'Aging incidents',
        'description' => 'When an incident you own remains unresolved for 48+ hours',
        'icon'        => 'fire',
    ],
    'risk_review_overdue' => [
        'label'       => 'Risk review overdue',
        'description' => 'When risks you own are past their scheduled review date',
        'icon'        => 'calendar-x-fill',
    ],
    'treatment_due' => [
        'label'       => 'Treatment actions due',
        'description' => 'When risk treatment actions are approaching their due date',
        'icon'        => 'tools',
    ],
    'risk_score_worsened' => [
        'label'       => 'Risk score increases',
        'description' => 'When a risk score increases since last assessment',
        'icon'        => 'graph-up-arrow',
    ],
    'vendor_assessment_expiring' => [
        'label'       => 'Vendor assessments due',
        'description' => 'When a vendor assessment is expiring within 30 days',
        'icon'        => 'building-gear',
    ],
    'document_expiring' => [
        'label'       => 'Documents expiring',
        'description' => 'When a document you own is approaching its expiry date',
        'icon'        => 'file-earmark-x-fill',
    ],
    'assessment_pending_stale' => [
        'label'       => 'Stale risk assessments',
        'description' => 'When a risk assessment has been waiting for approval for 48+ hours',
        'icon'        => 'hourglass-bottom',
    ],
    'evidence_expiring' => [
        'label'       => 'Evidence files expiring',
        'description' => 'When evidence files you uploaded are approaching their expiry date',
        'icon'        => 'paperclip',
    ],
];

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireAuth();
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $types = array_keys($NOTIF_TYPES);
    foreach ($types as $type) {
        $enabled = isset($_POST['prefs'][$type]);
        Database::query(
            "INSERT INTO user_notification_prefs (user_id, notification_type, enabled)
             VALUES (?, ?, ?)
             ON CONFLICT (user_id, notification_type)
             DO UPDATE SET enabled = EXCLUDED.enabled",
            [Auth::id(), $type, $enabled]
        );
    }
    Auth::log('update_notification_prefs', 'user', Auth::id());
    header('Location: /profile/notifications?saved=1');
    exit;
}

// ── Require auth ──────────────────────────────────────────────────────────────
Auth::requireAuth();

// ── Load current prefs ────────────────────────────────────────────────────────
$prefs = [];
foreach (Database::fetchAll(
    "SELECT notification_type, enabled FROM user_notification_prefs WHERE user_id = ?",
    [Auth::id()]
) as $r) {
    $prefs[$r['notification_type']] = (bool) $r['enabled'];
}
// Default: all enabled
foreach (array_keys($NOTIF_TYPES) as $type) {
    if (!array_key_exists($type, $prefs)) {
        $prefs[$type] = true;
    }
}

// ── Render ────────────────────────────────────────────────────────────────────
ob_start();
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" style="margin-bottom:20px">
  <i class="bi bi-check-circle-fill"></i>
  Notification preferences saved successfully.
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Notification Preferences</h1>
    <p class="page-subtitle">Choose which email notifications you receive from AEGIS GRC</p>
  </div>
</div>

<style>
/* Toggle switch pill */
.notif-toggle-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid var(--border, #e5e7eb);
    gap: 16px;
}
.notif-toggle-wrap:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.notif-toggle-info {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    flex: 1;
    min-width: 0;
}
.notif-toggle-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #eff6ff;
    color: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.notif-toggle-text .notif-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary, #111827);
    margin-bottom: 2px;
}
.notif-toggle-text .notif-desc {
    font-size: 13px;
    color: var(--text-muted, #6b7280);
}
/* The actual pill toggle */
.pill-toggle {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}
.pill-toggle input[type="checkbox"] {
    opacity: 0;
    width: 0;
    height: 0;
    position: absolute;
}
.pill-track {
    position: absolute;
    inset: 0;
    border-radius: 24px;
    background: #d1d5db;
    cursor: pointer;
    transition: background 0.2s;
}
.pill-toggle input:checked + .pill-track {
    background: #6366f1;
}
.pill-track::before {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
    transition: transform 0.2s;
}
.pill-toggle input:checked + .pill-track::before {
    transform: translateX(20px);
}
.pill-toggle input:focus-visible + .pill-track {
    outline: 2px solid #6366f1;
    outline-offset: 2px;
}
</style>

<div style="max-width:640px">
  <form method="POST" action="/profile/notifications">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-bell-fill" style="color:var(--primary,#6366f1)"></i>
          <span class="card-title">Email Notification Settings</span>
        </div>
      </div>
      <div class="card-body">
        <p style="font-size:14px;color:var(--text-muted,#6b7280);margin-top:0;margin-bottom:20px">
          Toggle the switches below to enable or disable each category of email
          notification. Changes take effect immediately and apply to the next
          scheduled notification run.
        </p>

        <?php foreach ($NOTIF_TYPES as $type => $meta):
            $isEnabled  = $prefs[$type] ?? true;
            $inputId    = 'pref_' . $type;
            $iconClass  = htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8');
            $label      = htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8');
            $desc       = htmlspecialchars($meta['description'], ENT_QUOTES, 'UTF-8');
        ?>
        <div class="notif-toggle-wrap">
          <div class="notif-toggle-info">
            <div class="notif-toggle-icon">
              <i class="bi bi-<?= $iconClass ?>"></i>
            </div>
            <div class="notif-toggle-text">
              <div class="notif-label"><?= $label ?></div>
              <div class="notif-desc"><?= $desc ?></div>
            </div>
          </div>
          <label class="pill-toggle" title="<?= $isEnabled ? 'Enabled' : 'Disabled' ?>">
            <input
              type="checkbox"
              name="prefs[<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>]"
              id="<?= $inputId ?>"
              value="1"
              <?= $isEnabled ? 'checked' : '' ?>
              aria-label="<?= $label ?>"
            >
            <span class="pill-track"></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer" style="padding:16px 20px;border-top:1px solid var(--border,#e5e7eb);display:flex;justify-content:flex-end">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Save Preferences
        </button>
      </div>
    </div>

  </form>
</div>

<?php
$content     = ob_get_clean();
$pageTitle   = 'Notification Preferences';
$activeModule = 'profile_notifications';
$breadcrumbs = [['Notification Preferences', null]];
require AEGIS_ROOT . '/views/layout.php';
