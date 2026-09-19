<?php

declare(strict_types=1);

/**
 * REDOUBT test runner. Usage: php tests/run.php
 *
 * Logic tests always run. Database tests run only when DATABASE_URL is set and
 * REDOUBT_TEST_DB=1 (otherwise they are skipped, so the suite is safe to run
 * anywhere). CI sets both against a throwaway PostgreSQL service.
 */

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib/T.php';
require __DIR__ . '/lib/Seed.php';

require __DIR__ . '/authorize_test.php';
require __DIR__ . '/modules_test.php';

exit(T::summary());
