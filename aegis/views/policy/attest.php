<?php
// Variables: $policy, $existing (existing attestation record or null)
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Attest Policy</h1>
    <p class="page-subtitle"><?= Security::h($policy['title']) ?></p>
  </div>
  <a href="/policy/<?= $policy['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Policy</a>
</div>

<?php if ($existing): ?>
  <div class="alert-box success" style="margin-bottom:20px">
    <i class="bi bi-check-circle-fill"></i>
    <strong>You have already attested this policy.</strong>
    Attested on <?= date('F j, Y \a\t g:i A', strtotime($existing['attested_at'])) ?>.
    You may re-attest below if needed.
  </div>
<?php endif; ?>

<div style="max-width:720px">

  <!-- Policy content box -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-file-earmark-text"></i> Policy Content</h3>
      <span class="badge">v<?= Security::h($policy['version']) ?></span>
    </div>
    <div class="card-body">
      <div id="policy-content-box" style="max-height:400px;overflow-y:scroll;border:1px solid var(--border);border-radius:var(--radius);padding:20px;background:var(--bg-secondary);line-height:1.7">
        <?php if ($policy['content']): ?>
          <?= Security::sanitizeHtml($policy['content']) ?>
        <?php else: ?>
          <p class="text-muted"><em>No content has been added to this policy yet.</em></p>
        <?php endif; ?>
      </div>
      <div id="scroll-hint" style="margin-top:8px;font-size:0.8rem;color:var(--text-muted);display:flex;align-items:center;gap:6px">
        <i class="bi bi-arrow-down-circle"></i> Scroll to read the full policy before attesting
      </div>
    </div>
  </div>

  <!-- Attestation form -->
  <div class="card" style="margin-top:16px">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-pen-fill"></i> Sign-Off</h3>
    </div>
    <div class="card-body">
      <form method="POST" action="/policy/<?= $policy['id'] ?>/attest" id="attest-form">
        <?= Security::csrfField() ?>

        <div class="form-group" style="background:var(--success-subtle);border:1px solid #16a34a40;border-radius:var(--radius);padding:16px">
          <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;font-weight:500">
            <input type="checkbox" name="confirmed" id="confirmed" value="1"
                   style="width:20px;height:20px;margin-top:2px;accent-color:#059669;flex-shrink:0">
            <span>
              I confirm that I have read and understood the
              <strong><?= Security::h($policy['title']) ?></strong> policy
              and agree to comply with its requirements.
            </span>
          </label>
        </div>

        <div class="form-group" style="margin-top:16px">
          <label class="form-label" for="notes">Notes <span class="text-muted">(optional)</span></label>
          <textarea name="notes" id="notes" class="form-control" rows="3"
                    placeholder="Any comments or clarifications..."><?= Security::h($_POST['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
          <button type="submit" id="attest-btn" class="btn btn-primary" disabled>
            <i class="bi bi-pen-fill"></i> I Attest
          </button>
          <a href="/policy/<?= $policy['id'] ?>" class="btn btn-ghost">Cancel</a>
        </div>

        <p class="text-muted text-sm" style="margin-top:12px">
          <i class="bi bi-lock-fill"></i>
          Your attestation will be recorded with your name, the date/time, and your IP address for audit purposes.
        </p>
      </form>
    </div>
  </div>

</div>

<script nonce="<?= Security::nonce() ?>">
(function () {
  var checkbox = document.getElementById('confirmed');
  var btn      = document.getElementById('attest-btn');
  var hint     = document.getElementById('scroll-hint');
  var box      = document.getElementById('policy-content-box');

  function updateBtn() {
    btn.disabled = !checkbox.checked;
  }

  if (checkbox) {
    checkbox.addEventListener('change', updateBtn);
  }

  // Hide scroll hint once user has scrolled near the bottom
  if (box && hint) {
    box.addEventListener('scroll', function () {
      if (box.scrollTop + box.clientHeight >= box.scrollHeight - 20) {
        hint.style.display = 'none';
      }
    });
  }
})();
</script>
