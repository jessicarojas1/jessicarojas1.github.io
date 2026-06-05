<?php
$breadcrumbs = [['Calendar', null]];
// Build prev/next month links
$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

$typeColors = [
    'control'      => 'var(--danger)',
    'policy_review'=> '#3b82f6',
    'audit'        => 'var(--primary)',
    'treatment'    => '#f97316',
];
$typeLabels = [
    'control'       => 'Control Due',
    'policy_review' => 'Policy Review',
    'audit'         => 'Audit',
    'treatment'     => 'Risk Treatment',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Compliance Calendar</h1>
    <p class="page-subtitle">Upcoming compliance deadlines, audits, policy reviews, and risk treatments</p>
  </div>
</div>

<!-- Calendar navigation -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:16px 20px">
    <a href="/calendar?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-secondary" style="min-width:100px">
      <i class="bi bi-chevron-left"></i> Prev
    </a>
    <div style="display:flex;align-items:center;gap:12px">
      <h2 style="margin:0;font-size:1.4rem;font-weight:700"><?= Security::h($monthName . ' ' . $year) ?></h2>
      <a href="/calendar" class="btn btn-secondary" style="font-size:0.85rem;padding:6px 14px">Today</a>
    </div>
    <a href="/calendar?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-secondary" style="min-width:100px">
      Next <i class="bi bi-chevron-right"></i>
    </a>
  </div>
</div>

<!-- Desktop calendar grid -->
<div class="card cal-desktop" style="margin-bottom:24px;overflow:hidden">
  <div style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:1px solid var(--border)">
    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
      <div style="padding:10px 0;text-align:center;font-size:0.78rem;font-weight:600;text-transform:uppercase;color:var(--text-muted);letter-spacing:.05em;background:var(--bg-secondary)">
        <?= $d ?>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr)">
    <?php
    // Empty cells before first day
    for ($i = 0; $i < $firstDayOfWeek; $i++):
    ?>
      <div style="min-height:100px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--bg-secondary);opacity:.4"></div>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $daysInMonth; $day++):
      $dateStr     = sprintf('%04d-%02d-%02d', $year, $month, $day);
      $isToday     = ($dateStr === $today);
      $dayEvents   = $events[$dateStr] ?? [];
      $extra       = max(0, count($dayEvents) - 3);
      $showEvents  = array_slice($dayEvents, 0, 3);
      $col         = ($firstDayOfWeek + $day - 1) % 7;
      $isLastCol   = ($col === 6);
    ?>
      <div style="min-height:100px;padding:6px;border-right:<?= $isLastCol ? 'none' : '1px solid var(--border)' ?>;border-bottom:1px solid var(--border);background:<?= $isToday ? 'rgba(99,102,241,.07)' : 'var(--bg-primary)' ?>;position:relative">
        <div style="font-size:0.82rem;font-weight:<?= $isToday ? '700' : '500' ?>;color:<?= $isToday ? 'var(--primary)' : 'var(--text-primary)' ?>;margin-bottom:4px">
          <?php if ($isToday): ?>
            <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:var(--primary);color:#fff;font-size:0.78rem">
              <?= $day ?>
            </span>
          <?php else: ?>
            <?= $day ?>
          <?php endif; ?>
        </div>
        <?php foreach ($showEvents as $ev): ?>
          <a href="<?= Security::h($ev['url']) ?>"
             title="<?= Security::h($ev['title']) ?>"
             style="display:flex;align-items:center;gap:4px;padding:2px 5px;margin-bottom:2px;border-radius:4px;font-size:0.72rem;font-weight:500;text-decoration:none;color:#fff;background:<?= Security::h($typeColors[$ev['type']] ?? 'var(--text-muted)') ?>;overflow:hidden;white-space:nowrap;max-width:100%">
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis"><?= Security::h($ev['title']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($extra > 0): ?>
          <div style="font-size:0.7rem;color:var(--text-muted);padding:1px 5px">+<?= $extra ?> more</div>
        <?php endif; ?>
      </div>
    <?php endfor; ?>

    <?php
    // Trailing empty cells to complete the last row
    $totalCells = $firstDayOfWeek + $daysInMonth;
    $remainder  = $totalCells % 7;
    if ($remainder > 0):
      for ($i = $remainder; $i < 7; $i++):
    ?>
        <div style="min-height:100px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);background:var(--bg-secondary);opacity:.4"></div>
    <?php
      endfor;
    endif;
    ?>
  </div>
</div>

<!-- Mobile list view -->
<div class="cal-mobile" style="display:none">
  <div class="card">
    <div class="card-header"><h3 style="margin:0">Events This Month</h3></div>
    <div class="card-body" style="padding:0">
      <?php
      // Flatten and sort by date
      $allEvents = [];
      foreach ($events as $dateStr => $dayEvents) {
          foreach ($dayEvents as $ev) {
              $allEvents[] = array_merge($ev, ['date' => $dateStr]);
          }
      }
      usort($allEvents, fn($a, $b) => strcmp($a['date'], $b['date']));
      ?>
      <?php if (empty($allEvents)): ?>
        <div style="padding:32px;text-align:center;color:var(--text-muted)">
          <i class="bi bi-calendar-check" style="font-size:2rem;display:block;margin-bottom:8px"></i>
          No events scheduled this month
        </div>
      <?php else: ?>
        <?php $lastDate = null; foreach ($allEvents as $ev): ?>
          <?php if ($ev['date'] !== $lastDate): $lastDate = $ev['date']; ?>
            <div style="padding:8px 16px;font-size:0.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);background:var(--bg-secondary);border-top:1px solid var(--border)">
              <?= Security::h(date('l, F j', strtotime($ev['date']))) ?>
              <?php if ($ev['date'] === $today): ?>
                <span style="margin-left:6px;padding:1px 6px;border-radius:999px;font-size:0.7rem;background:var(--primary);color:#fff">Today</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <a href="<?= Security::h($ev['url']) ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;text-decoration:none;color:var(--text-primary);border-top:1px solid var(--border)">
            <span style="width:10px;height:10px;border-radius:50%;flex-shrink:0;background:<?= Security::h($typeColors[$ev['type']] ?? 'var(--text-muted)') ?>"></span>
            <span style="flex:1;font-size:0.88rem"><?= Security::h($ev['title']) ?></span>
            <span style="font-size:0.75rem;color:var(--text-muted)"><?= Security::h($typeLabels[$ev['type']] ?? $ev['type']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Legend -->
<div class="card">
  <div class="card-body" style="display:flex;flex-wrap:wrap;gap:20px;align-items:center;padding:14px 20px">
    <span style="font-size:0.82rem;font-weight:600;color:var(--text-muted);margin-right:4px">Legend:</span>
    <?php foreach ($typeLabels as $type => $label): ?>
      <span style="display:flex;align-items:center;gap:6px;font-size:0.83rem">
        <span style="width:10px;height:10px;border-radius:50%;background:<?= Security::h($typeColors[$type]) ?>"></span>
        <?= Security::h($label) ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<style>
@media (max-width: 640px) {
  .cal-desktop { display: none !important; }
  .cal-mobile  { display: block !important; }
}
</style>
