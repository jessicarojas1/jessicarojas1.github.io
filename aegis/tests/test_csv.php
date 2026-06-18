<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Csv.php';

it('prefixes formula-trigger characters with a quote', function () {
    expect_eq("'=1+1",            Csv::cell('=1+1'));
    expect_eq("'+1",             Csv::cell('+1'));
    expect_eq("'-1",             Csv::cell('-1'));
    expect_eq("'@SUM(A1:A9)",    Csv::cell('@SUM(A1:A9)'));
    expect_eq("'\tdata",         Csv::cell("\tdata"));
    expect_eq("'\rdata",         Csv::cell("\rdata"));
});

it('neutralizes the classic DDE exfiltration payload', function () {
    $payload = '=cmd|\' /C calc\'!A0';
    expect(str_starts_with(Csv::cell($payload), "'="), 'DDE payload not neutralized');
});

it('leaves ordinary values untouched', function () {
    expect_eq('Acme Corp',       Csv::cell('Acme Corp'));
    expect_eq('123',             Csv::cell('123'));
    expect_eq('user@example.com',Csv::cell('user@example.com')); // @ not at start
    expect_eq('a-b-c',           Csv::cell('a-b-c'));            // - not at start
    expect_eq('',                Csv::cell(''));
    expect_eq('',                Csv::cell(null));
});

it('row() guards every cell', function () {
    $row = Csv::row(['ok', '=evil()', 5, '-2']);
    expect_eq(['ok', "'=evil()", '5', "'-2"], $row);
});

it('coerces non-strings to string', function () {
    expect_eq('42', Csv::cell(42));
    expect_eq('1',  Csv::cell(true));
});
