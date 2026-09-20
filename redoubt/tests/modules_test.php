<?php

declare(strict_types=1);

use Redoubt\Support\Db;
use Redoubt\Support\Announcements;
use Redoubt\Support\Authorize;
use Redoubt\Support\Documents;
use Redoubt\Support\TaskOrders;
use Redoubt\Support\Jobs;
use Redoubt\Support\Directory;
use Redoubt\Support\Search;
use Redoubt\Support\Settings;
use Redoubt\Support\Notifications;

/** Database-backed module tests (skipped unless a throwaway test DB is provided). */

T::group('Module data path (live DB)');

if (!Db::isConfigured() || getenv('REDOUBT_TEST_DB') !== '1') {
    T::skip('DB tests (set DATABASE_URL + REDOUBT_TEST_DB=1 to run)');
    return;
}

Seed::reset();
$ids = Seed::fixture();
$pid = $ids['prog'];

$pm   = Seed::user($ids['pm'], 'internal', true, $pid, ['program_manager'], $ids['gmre'], []);
$sam  = Seed::user($ids['sam'], 'external', true, $pid, ['sub_member'], $ids['acme'], [$ids['acme']]);
$nina = Seed::user($ids['nina'], 'external', false, $pid, ['sub_member'], $ids['acme'], [$ids['acme']]);
$recruiter = Seed::user($ids['pm'], 'internal', true, $pid, ['recruiting'], null, []);

$lastEvent = static fn (): string => (string) (Db::fetchOne('SELECT event FROM webhook_delivery ORDER BY id DESC LIMIT 1')['event'] ?? '');

// Announcements
$aid = Announcements::create($pid, ['title' => 'Kickoff', 'body' => 'x', 'audience' => ['all'], 'priority' => 'high'], $ids['pm']);
T::ok('announcement create returns id', $aid > 0);
T::eq('PM sees the draft', 1, count(Announcements::listForUser($pm, $pid)));
Announcements::publish($aid, $pid, $ids['pm']);
T::eq('published announcement is live', 1, count(Announcements::listPublished($pid)));
T::eq('announcement.published webhook delivered', 'announcement.published', $lastEvent());

// IAM explicit grant upsert/delete
$up = 'INSERT INTO user_permission_grant (program_id,user_id,permission_key,effect,granted_by) VALUES (:p,:u,:k,:e,:b)
       ON CONFLICT (program_id,user_id,permission_key) DO UPDATE SET effect=EXCLUDED.effect, granted_at=NOW()';
Db::query($up, ['p' => $pid, 'u' => $ids['sam'], 'k' => 'announcement.publish', 'e' => 'grant', 'b' => $ids['pm']]);
$samGranted = Seed::user($ids['sam'], 'external', true, $pid, ['sub_member'], $ids['acme'], [$ids['acme']], ['grant' => ['announcement.publish'], 'deny' => []]);
T::ok('explicit grant lets sub publish', Authorize::can($samGranted, 'announcement.publish', ['program_id' => $pid]));
Db::query($up, ['p' => $pid, 'u' => $ids['sam'], 'k' => 'announcement.publish', 'e' => 'deny', 'b' => $ids['pm']]);
T::eq('ON CONFLICT upsert grant->deny', 'deny', Db::fetchOne('SELECT effect FROM user_permission_grant WHERE program_id=:p AND user_id=:u AND permission_key=:k', ['p' => $pid, 'u' => $ids['sam'], 'k' => 'announcement.publish'])['effect']);
Db::query('DELETE FROM user_permission_grant WHERE program_id=:p AND user_id=:u AND permission_key=:k', ['p' => $pid, 'u' => $ids['sam'], 'k' => 'announcement.publish']);
T::ok('delete reverts to role default', Db::fetchOne('SELECT 1 FROM user_permission_grant WHERE program_id=:p AND user_id=:u AND permission_key=:k', ['p' => $pid, 'u' => $ids['sam'], 'k' => 'announcement.publish']) === null);

// Documents zone/scope/export gating
T::eq('PM sees all 3 documents', 3, count(Documents::listForUser($pm, $pid)));
T::eq('Sub(US) sees 2 (project + export, not Beta)', 2, count(Documents::listForUser($sam, $pid)));
T::eq('Sub(non-US) sees 1 (export blocked)', 1, count(Documents::listForUser($nina, $pid)));
$itar = Db::fetchOne('SELECT * FROM document_ref WHERE title=:t AND program_id=:p', ['t' => 'Falcon classified', 'p' => $pid]);
T::ok('canSee ITAR: non-US denied', !Documents::canSee($nina, $itar, $pid));
T::ok('canSee ITAR: US allowed', Documents::canSee($sam, $itar, $pid));
T::eq('resolveOpenUrl falls back to web_url', 'https://x/c', Documents::resolveOpenUrl($itar));
T::eq('API-client doc view excludes export-controlled', 0, count(array_filter(Documents::listForApiClient($pid), static fn ($d) => $d['export_controlled'])));

// Task Orders scoping + award webhook
T::eq('PM sees both TOs', 2, count(TaskOrders::listForUser($pm, $pid)));
T::eq('Sub sees only Acme TO', 1, count(TaskOrders::listForUser($sam, $pid)));
$toA = (int) Db::fetchOne('SELECT id FROM task_order WHERE number=:n AND program_id=:p', ['n' => 'TO-A', 'p' => $pid])['id'];
TaskOrders::approve($toA, $pid, $ids['pm']);
T::eq('approve sets awarded', 'awarded', TaskOrders::get($toA, $pid)['status']);
T::eq('taskorder.awarded webhook delivered', 'taskorder.awarded', $lastEvent());

// Jobs draft->post
$jid = Jobs::create($pid, ['title' => 'Engineer', 'audience' => ['all']], $ids['pm']);
T::eq('sub sees no draft job', 0, count(Jobs::listForUser($sam, $pid)));
T::eq('recruiter (editor) sees the draft', 1, count(Jobs::listForUser($recruiter, $pid)));
Jobs::publish($jid, $pid, $ids['pm']);
T::eq('sub sees the posted job', 1, count(Jobs::listForUser($sam, $pid)));
T::eq('job.posted webhook delivered', 'job.posted', $lastEvent());

// Job targeting: program-wide / by company / by contract (task order)
$toA = (int) Db::fetchOne("SELECT id FROM task_order WHERE number='TO-A' AND program_id=:p", ['p' => $pid])['id'];
$mkJob = static function (string $t, array $target) use ($pid, $ids): int {
    $id = Jobs::create($pid, ['title' => $t] + $target, $ids['pm']);
    Jobs::publish($id, $pid, $ids['pm']);
    return $id;
};
$mkJob('J_all',  ['audience' => ['all']]);
$mkJob('J_acme', ['audience' => [], 'company_scope' => [$ids['acme']], 'task_order_scope' => []]);
$mkJob('J_beta', ['audience' => [], 'company_scope' => [$ids['beta']], 'task_order_scope' => []]);
$mkJob('J_toA',  ['audience' => [], 'company_scope' => [], 'task_order_scope' => [$toA]]);   // TO-A is scoped to Acme
$samJobTitles = static fn (array $f = []) => array_map(static fn ($j) => $j['title'], Jobs::listForUser($sam, $pid, $f));
$sees = $samJobTitles();
T::ok('Acme sub sees program-wide job', in_array('J_all', $sees, true));
T::ok('Acme sub sees company-targeted (Acme) job', in_array('J_acme', $sees, true));
T::ok('Acme sub does NOT see company-targeted (Beta) job', !in_array('J_beta', $sees, true));
T::ok('Acme sub sees contract-targeted job (via TO-A→Acme)', in_array('J_toA', $sees, true));
$prog = $samJobTitles(['scope' => 'program']);
T::ok('filter scope=program shows program-wide only', in_array('J_all', $prog, true) && !in_array('J_acme', $prog, true) && !in_array('J_toA', $prog, true));
$byTo = $samJobTitles(['task_order_id' => $toA]);
T::ok('filter by task order returns that contract\'s job', in_array('J_toA', $byTo, true) && !in_array('J_all', $byTo, true));
$byCo = $samJobTitles(['company_id' => $ids['acme']]);
T::ok('filter by company returns company + contract-scoped jobs', in_array('J_acme', $byCo, true) && in_array('J_toA', $byCo, true) && !in_array('J_all', $byCo, true));

// Directory visibility
$samContacts = array_map(static fn ($c) => $c['name'], Directory::listForUser($sam, $pid));
T::ok('sub sees all-visibility contact', in_array('Falcon lead', $samContacts, true));
T::ok('sub does NOT see internal-only contact', !in_array('Internal only', $samContacts, true));
T::eq('PM (internal) sees both contacts', 2, count(Directory::listForUser($pm, $pid)));

// Search non-leakage
$ninaHits = array_map(static fn ($r) => $r['title'], Search::run($nina, $pid, 'falcon'));
T::ok('search: non-US finds project doc', in_array('Falcon overview', $ninaHits, true));
T::ok('search: non-US does NOT leak ITAR doc', !in_array('Falcon classified', $ninaHits, true));
$pmHits = array_map(static fn ($r) => $r['title'], Search::run($pm, $pid, 'falcon'));
T::ok('search: US+internal DOES include ITAR doc', in_array('Falcon classified', $pmHits, true));
T::eq('search: empty query returns nothing', [], Search::run($nina, $pid, ''));

// Settings + Branding
T::group('Settings & Branding (live DB)');
Settings::saveBranding($pid, ['logoUrl' => 'https://cdn.example.us/logo.png', 'displayName' => 'Falcon Program', 'accent' => '#0a1a30'], $ids['pm']);
$brand = Settings::branding($pid);
T::eq('branding display name persists', 'Falcon Program', $brand['displayName']);
T::eq('branding accent persists', '#0a1a30', $brand['accent']);
T::eq('branding https logo URL persists', 'https://cdn.example.us/logo.png', $brand['logoUrl']);
Settings::saveBranding($pid, ['logoUrl' => 'javascript:alert(1)', 'displayName' => 'X', 'accent' => 'red'], $ids['pm']);
$bad = Settings::branding($pid);
T::eq('unsafe logo URL rejected -> null', null, $bad['logoUrl']);
T::eq('non-hex accent rejected -> null', null, $bad['accent']);
T::ok('data: image logo URL accepted', Settings::safeLogoUrl('data:image/png;base64,iVBORw0KGgo=') !== null);
Settings::saveJobs($pid, false, $ids['pm']);
T::eq('jobs default program-wide persists (false)', false, Settings::jobsDefaultProgramWide($pid));

// Notifications fan-out (permission-trimmed)
T::group('Notifications fan-out (live DB)');
$aAll = Announcements::create($pid, ['title' => 'All-hands sync', 'body' => 'x', 'audience' => ['all'], 'priority' => 'normal'], $ids['pm']);
Announcements::publish($aAll, $pid, $ids['pm']);
$aInt = Announcements::create($pid, ['title' => 'Internal memo', 'body' => 'x', 'audience' => ['internal'], 'priority' => 'normal'], $ids['pm']);
Announcements::publish($aInt, $pid, $ids['pm']);
$samNotif = array_map(static fn ($n) => $n['title'], Notifications::listFor($ids['sam']));
T::ok('sub notified of program-wide announcement', in_array('All-hands sync', $samNotif, true));
T::ok('sub NOT notified of internal-only announcement', !in_array('Internal memo', $samNotif, true));
$pmNotif = array_map(static fn ($n) => $n['title'], Notifications::listFor($ids['pm']));
T::ok('actor (PM) is not self-notified', !in_array('All-hands sync', $pmNotif, true));
T::ok('unread count > 0 for notified sub', Notifications::unreadCount($ids['sam']) > 0);
Notifications::markAllRead($ids['sam']);
T::eq('mark-all-read clears unread', 0, Notifications::unreadCount($ids['sam']));
