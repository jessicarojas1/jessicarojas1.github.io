<?php

declare(strict_types=1);

use Redoubt\Support\Authorize;
use Redoubt\Support\PermissionCatalog;

/** Pure authorization-engine tests (no database). */

T::group('Authorization engine');

$sub  = Seed::user(1, 'external', true, 1, ['sub_member'], 7, [7]);
$pm   = Seed::user(2, 'internal', true, 1, ['program_manager'], null, []);
$ent  = Seed::user(3, 'internal', true, 1, ['enterprise_admin'], null, []);

// Export gate
T::ok('non-US enterprise_admin denied export-controlled', !Authorize::can(array_replace($ent, ['is_us_person' => false]), 'document.view', ['program_id' => 1, 'export_controlled' => true]));
T::ok('US enterprise_admin allowed export-controlled', Authorize::can($ent, 'document.view', ['program_id' => 1, 'export_controlled' => true]));

// Role defaults
T::ok('sub_member can announcement.view', Authorize::can($sub, 'announcement.view', ['program_id' => 1]));
T::ok('sub_member cannot announcement.publish', !Authorize::can($sub, 'announcement.publish', ['program_id' => 1]));
T::ok('program_manager can announcement.publish', Authorize::can($pm, 'announcement.publish', ['program_id' => 1]));

// Explicit grant / deny (denials win)
$g = Seed::user(1, 'external', true, 1, ['sub_member'], 7, [7], ['grant' => ['announcement.publish'], 'deny' => []]);
T::ok('explicit grant adds announcement.publish', Authorize::can($g, 'announcement.publish', ['program_id' => 1]));
$d = Seed::user(2, 'internal', true, 1, ['program_manager'], null, [], ['grant' => [], 'deny' => ['announcement.publish']]);
T::ok('explicit deny overrides role default', !Authorize::can($d, 'announcement.publish', ['program_id' => 1]));

// Company scoping
T::ok('company 7 denied doc scoped to company 9', !Authorize::can($sub, 'document.view', ['program_id' => 1, 'company_scope' => [9]]));
T::ok('company 7 allowed doc scoped to company 7', Authorize::can($sub, 'document.view', ['program_id' => 1, 'company_scope' => [7]]));
T::ok('internal PM bypasses company scope', Authorize::can($pm, 'document.view', ['program_id' => 1, 'company_scope' => [9]]));

// Program membership
T::ok('no access to a non-member program', !Authorize::can($sub, 'announcement.view', ['program_id' => 2]));

// Alias expansion (coarse requires all granular)
$partial = Seed::user(1, 'external', true, 1, ['sub_member'], 7, [7], ['grant' => ['announcement.create', 'announcement.edit'], 'deny' => []]);
T::ok('coarse announcement.write NOT satisfied without publish', !Authorize::can($partial, 'announcement.write', ['program_id' => 1]));
$full = Seed::user(1, 'external', true, 1, ['sub_member'], 7, [7], ['grant' => ['announcement.create', 'announcement.edit', 'announcement.publish'], 'deny' => []]);
T::ok('coarse announcement.write satisfied with all granular', Authorize::can($full, 'announcement.write', ['program_id' => 1]));

// Catalog sanity
T::ok('PermissionCatalog has >20 keys', PermissionCatalog::total() > 20);
T::ok('effectivePermissions for PM non-empty', count(Authorize::effectivePermissions($pm, 1)) > 0);
