<?php
/** Program Assistant. In scope: $user,$NONCE,$programs,$programId,$answer(array|null). */
use Redoubt\Support\Security;

$title = 'Assistant';
$navActive = 'assistant';
$breadcrumbs = ['Home' => '/app', 'Assistant' => null];
require __DIR__ . '/partials/app_header.php';
$q = trim((string) ($_GET['q'] ?? ''));
$examples = ['When is the kickoff?', 'How do I badge in?', 'Latest task order', 'Open positions', 'Security POC'];
?>
<div class="page-header">
  <h1 class="page-title">Program Assistant</h1>
  <?php if (count($programs) > 1): ?>
  <form method="get" action="/app/assistant" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <form method="get" action="/app/assistant" style="display:flex;gap:8px">
    <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
    <input type="search" name="q" value="<?= Security::h($q) ?>" placeholder="Ask about this program — announcements, documents, task orders, jobs, dates, FAQ…" autofocus style="flex:1">
    <button class="btn btn-primary" type="submit">Ask</button>
  </form>
  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($examples as $ex): ?><a class="btn btn-sm" href="/app/assistant?program_id=<?= (int) $programId ?>&amp;q=<?= Security::h(rawurlencode($ex)) ?>"><?= Security::h($ex) ?></a><?php endforeach; ?>
  </div>
  <p class="perm-key" style="margin:10px 0 0">Answers are drawn only from program information you are authorized to see — the assistant cannot surface anything outside your access (export-controlled items, other companies' data, restricted zones).</p>
</div>

<?php if ($answer !== null): ?>
<div class="card">
  <h3 class="dash-h">Answer</h3>
  <div style="font-size:15px;white-space:pre-wrap"><?= Security::h((string) $answer['summary']) ?></div>
</div>
<?php if (($answer['results'] ?? []) !== []): ?>
<div class="card">
  <h3 class="dash-h">Sources (<?= (int) $answer['count'] ?>)</h3>
  <?php foreach ($answer['results'] as $r): ?>
    <a href="<?= Security::h($r['url']) ?>" style="display:block;padding:10px 0;border-bottom:1px dashed var(--line);color:inherit">
      <span class="badge b-muted"><?= Security::h((string) $r['type']) ?></span>
      <strong style="margin-left:6px"><?= Security::h((string) $r['title']) ?></strong>
      <div class="perm-key" style="margin-top:3px"><?= Security::h((string) ($r['snippet'] ?? '')) ?></div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
