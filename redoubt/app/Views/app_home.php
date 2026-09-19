<?php
/**
 * Authenticated application home (Phase 1 skeleton).
 * Provided by AppController::home(). $user, $capabilities, $NONCE in scope.
 */
use Redoubt\Support\Security;

$user = $user ?? [];
$NONCE = $NONCE ?? '';
$name = Security::h($user['name'] ?? 'User');
$email = Security::h($user['email'] ?? '');
$usPerson = $user['is_us_person'] ?? null;
$memberships = $user['memberships'] ?? [];
$capabilities = $capabilities ?? [];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>REDOUBT — Program Portal</title>
<style nonce="<?= Security::h($NONCE) ?>">
:root{--navy:#0a1a30;--gold:#c8992e;--ink:#0f1b2d;--muted:#5a6a7e;--bg:#f6f8fb;--card:#fff;--line:#e2e8f0;--accent:#1e6fb8;--ok:#1f9d55;--warn:#c8992e}
@media(prefers-color-scheme:dark){:root{--ink:#e7eef7;--muted:#9fb0c4;--bg:#0a1220;--card:#0f1b2d;--line:#20304a;--accent:#4a97dd}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
a{color:var(--accent);text-decoration:none}
.top{background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:3px solid var(--gold)}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;color:#fff}
.logo{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,var(--gold),#e6c05a);display:inline-block}
.top .who{font-size:13px;color:#cfe0f2}
.top .who a{color:#fff;margin-left:14px}
.wrap{max-width:1000px;margin:0 auto;padding:24px 20px 80px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin:0 0 16px}
h1{font-size:22px;margin:6px 0 2px}
.sub{color:var(--muted);margin:0 0 18px}
.badge{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;border:1px solid}
.b-ok{color:var(--ok);border-color:var(--ok);background:color-mix(in srgb,var(--ok) 12%,transparent)}
.b-warn{color:var(--warn);border-color:var(--warn);background:color-mix(in srgb,var(--warn) 14%,transparent)}
.b-muted{color:var(--muted);border-color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.mod{border:1px solid var(--line);border-radius:10px;padding:14px;background:var(--bg)}
.mod h4{margin:0 0 4px;font-size:14px}
.mod p{margin:0;font-size:12.5px;color:var(--muted)}
.perm{font:12px/1.5 ui-monospace,Menlo,monospace;color:var(--muted);word-break:break-word}
.note{border-left:4px solid var(--accent);background:color-mix(in srgb,var(--accent) 7%,transparent);padding:10px 14px;border-radius:0 8px 8px 0;font-size:13px;color:var(--muted)}
</style>
</head>
<body>
<header class="top">
  <a class="brand" href="/app"><span class="logo"></span> REDOUBT</a>
  <div class="who"><?= $name ?><?php if ($email): ?> · <?= $email ?><?php endif; ?><a href="/auth/logout">Sign out</a></div>
</header>
<div class="wrap">
  <div class="card">
    <h1>Welcome, <?= $name ?></h1>
    <p class="sub">Program portal — authenticated session (Phase 1 skeleton).</p>
    <p>
      <?php if ($usPerson === true): ?>
        <span class="badge b-ok">US person — export-controlled access eligible</span>
      <?php elseif ($usPerson === false): ?>
        <span class="badge b-warn">Non-US person — ITAR/EAR zones blocked</span>
      <?php else: ?>
        <span class="badge b-muted">US-person status not yet verified</span>
      <?php endif; ?>
    </p>
  </div>

  <div class="card">
    <h4 style="margin:0 0 10px">Your programs &amp; effective permissions</h4>
    <?php if ($memberships === []): ?>
      <div class="note">You are signed in, but you have no program access yet. A program administrator must assign you to a program and role. (Server-side authorization is enforced — no data is shown without an explicit grant.)</div>
    <?php else: ?>
      <?php foreach ($memberships as $pid => $m): ?>
        <div class="mod" style="margin-bottom:10px">
          <h4>Program #<?= (int) $pid ?> — roles: <?= Security::h(implode(', ', $m['roles'] ?? [])) ?></h4>
          <p class="perm"><?= Security::h(implode('  ·  ', $capabilities[$pid] ?? [])) ?: 'no permissions' ?></p>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h4 style="margin:0 0 10px">Modules</h4>
    <div class="grid">
      <?php foreach (['Announcements','Documents','Customer / COR','Task Orders','Jobs','Directory','Search','Administration'] as $mod): ?>
        <div class="mod"><h4><?= Security::h($mod) ?></h4><p>Phase 1 — mounts here</p></div>
      <?php endforeach; ?>
    </div>
    <p class="note" style="margin-top:14px">This authenticated shell proves the pipeline: Entra GCC High sign-in → session → membership/grant load → server-side authorization + audit. Module UIs and the REST API (<code>/api/v1</code>) build on these services. See <code>OPEN_ITEMS.md</code>.</p>
  </div>
</div>
</body>
</html>
