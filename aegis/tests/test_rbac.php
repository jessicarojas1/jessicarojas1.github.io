<?php
declare(strict_types=1);

// roleDefaultPermissions(), roles(), and isValidRole() are pure/static and do
// not touch the database, so they are safe to test in isolation.
require_once __DIR__ . '/../src/Auth.php';

it('admin resolves to the all-permissions sentinel', function () {
    expect_eq(['*'], Auth::roleDefaultPermissions('admin'));
});

it('viewer can view but not create or accept risks', function () {
    $p = Auth::roleDefaultPermissions('viewer');
    expect(in_array('risk.view', $p, true), 'viewer lacks risk.view');
    expect(!in_array('risk.create', $p, true), 'viewer should not create risks');
    expect(!in_array('risk.accept', $p, true), 'viewer should not accept risks');
});

it('auditor owns audits/findings and reads broadly (regression: was unmapped)', function () {
    $p = Auth::roleDefaultPermissions('auditor');
    expect($p !== [], 'auditor must not be empty — that was the bug');
    expect(in_array('audit.close', $p, true), 'auditor lacks audit.close');
    expect(in_array('audit.findings', $p, true), 'auditor lacks audit.findings');
    expect(in_array('risk.view', $p, true), 'auditor lacks risk.view');
    expect(!in_array('risk.delete', $p, true), 'auditor should not delete risks');
});

it('risk_owner owns the risk lifecycle including acceptance', function () {
    $p = Auth::roleDefaultPermissions('risk_owner');
    expect(in_array('risk.accept', $p, true), 'risk_owner lacks risk.accept');
    expect(in_array('kri.record', $p, true), 'risk_owner lacks kri.record');
    expect(!in_array('audit.close', $p, true), 'risk_owner should not close audits');
});

it('control_owner can assess controls and attest policies', function () {
    $p = Auth::roleDefaultPermissions('control_owner');
    expect(in_array('compliance.assess', $p, true), 'control_owner lacks compliance.assess');
    expect(in_array('policy.attest', $p, true), 'control_owner lacks policy.attest');
    expect(!in_array('risk.delete', $p, true), 'control_owner should not delete risks');
});

it('executive reads everything and approves', function () {
    $p = Auth::roleDefaultPermissions('executive');
    expect(in_array('report.view', $p, true), 'executive lacks report.view');
    expect(in_array('approval.approve', $p, true), 'executive lacks approval.approve');
    expect(!in_array('risk.create', $p, true), 'executive should not create risks');
});

it('an unknown role resolves to no permissions', function () {
    expect_eq([], Auth::roleDefaultPermissions('superuser'));
});

it('roles() exposes all eight assignable roles', function () {
    $roles = Auth::roles();
    foreach (['admin','manager','auditor','control_owner','risk_owner','analyst','executive','viewer'] as $r) {
        expect(isset($roles[$r]), "missing role: {$r}");
    }
});

it('isValidRole accepts canonical roles and rejects others', function () {
    expect(Auth::isValidRole('auditor'), 'auditor should be valid');
    expect(Auth::isValidRole('executive'), 'executive should be valid');
    expect(!Auth::isValidRole('root'), 'root should be invalid');
    expect(!Auth::isValidRole(''), 'empty should be invalid');
});

it('risk.write alias expands to the granular write permissions incl. bowtie (regression)', function () {
    // The coarse 'risk.write' alias must cover bow-tie like it already covers
    // scenarios — both are the same diagram-editing feature class.
    $ref = new ReflectionClass('Auth');
    $prop = $ref->getProperty('aliases');
    $prop->setAccessible(true);
    $map = $prop->getValue();
    $rw = $map['risk.write'] ?? [];
    expect(in_array('risk.bowtie', $rw, true), 'risk.write alias is missing risk.bowtie (the regression)');
    expect(in_array('risk.scenarios', $rw, true), 'risk.write alias is missing risk.scenarios');
    expect(in_array('risk.create', $rw, true), 'risk.write alias is missing risk.create');
});
