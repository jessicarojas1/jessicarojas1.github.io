<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= Security::h($vendor['name']) ?> — Security Assessment</title>
  <link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 0; }
    .header { background: #0f172a; color: white; padding: 20px 40px; display: flex; align-items: center; gap: 12px; }
    .header h1 { margin: 0; font-size: 18px; }
    .content { max-width: 760px; margin: 40px auto; padding: 0 20px; }
    .card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 24px; }
    .question { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #e2e8f0; }
    .question:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .question label { display: block; font-weight: 600; margin-bottom: 8px; }
    .required-star { color: #ef4444; }
    textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; min-height: 100px; resize: vertical; font-family: inherit; }
    textarea:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.1); }
    .btn { padding: 12px 28px; border-radius: 8px; border: none; cursor: pointer; font-size: 15px; font-weight: 600; }
    .btn-primary { background: #4f46e5; color: white; }
    .btn-primary:hover { background: #4338ca; }
    .footer { text-align: center; color: #94a3b8; font-size: 13px; padding: 20px; }
    .meta { color: #64748b; font-size: 13px; margin-bottom: 20px; }
    .progress-bar { height: 4px; background: #e2e8f0; border-radius: 2px; margin-bottom: 24px; }
    .progress-fill { height: 100%; background: #4f46e5; border-radius: 2px; transition: width .3s; }
  </style>
</head>
<body>
<div class="header">
  <i class="bi bi-shield-fill-check" style="font-size:24px;color:#818cf8"></i>
  <div>
    <h1>AEGIS GRC — Vendor Security Assessment</h1>
    <div style="font-size:13px;opacity:.7">Confidential — <?= Security::h($vendor['name']) ?></div>
  </div>
</div>
<div class="content">
  <div class="card">
    <h2 style="margin-top:0"><?= Security::h($rec['title']) ?></h2>
    <div class="meta">
      <i class="bi bi-building"></i> <?= Security::h($vendor['name']) ?> &nbsp;|&nbsp;
      <i class="bi bi-clock"></i> Expires <?= date('M j, Y', strtotime($rec['expires_at'])) ?> &nbsp;|&nbsp;
      <?= count($questions) ?> questions
    </div>
    <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
    <p style="color:#64748b;margin-top:0">Please answer each question as completely as possible. Required questions are marked with <span class="required-star">*</span>. Your responses are encrypted in transit and stored securely.</p>
  </div>

  <form method="POST" action="/vendor/portal/<?= Security::h($token) ?>/submit" onsubmit="return validateForm()">
    <input type="hidden" name="csrf_token" value="<?= Security::h(hash('sha256', $token)) ?>">

    <?php foreach ($questions as $i => $q): ?>
    <div class="card question" id="q-<?= (int)$q['id'] ?>">
      <label for="ans-<?= (int)$q['id'] ?>">
        <?= (int)$i + 1 ?>. <?= Security::h($q['text']) ?>
        <?php if (!empty($q['required'])): ?><span class="required-star"> *</span><?php endif; ?>
      </label>
      <textarea id="ans-<?= (int)$q['id'] ?>" name="answers[<?= (int)$q['id'] ?>]"
                placeholder="Your response…"
                oninput="updateProgress()"
                <?= !empty($q['required']) ? 'data-required="1"' : '' ?>></textarea>
    </div>
    <?php endforeach; ?>

    <div class="card" style="text-align:center">
      <p style="color:#64748b;margin-top:0">By submitting this form you confirm the information provided is accurate to the best of your knowledge.</p>
      <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i> Submit Assessment</button>
    </div>
  </form>

  <div class="footer">Powered by AEGIS GRC &mdash; Responses are encrypted and stored securely.</div>
</div>
<script nonce="<?= Security::nonce() ?>">
function updateProgress() {
  var textareas = document.querySelectorAll('textarea');
  var filled = 0;
  textareas.forEach(function(t) { if (t.value.trim()) filled++; });
  document.getElementById('progressFill').style.width = Math.round(filled / textareas.length * 100) + '%';
}
function validateForm() {
  var required = document.querySelectorAll('textarea[data-required="1"]');
  var ok = true;
  required.forEach(function(t) {
    if (!t.value.trim()) {
      t.style.borderColor = '#ef4444';
      ok = false;
    } else {
      t.style.borderColor = '';
    }
  });
  if (!ok) alert('Please fill in all required fields before submitting.');
  return ok;
}
</script>
</body>
</html>
