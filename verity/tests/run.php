<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib/T.php';

require __DIR__ . '/unit_test.php';
require __DIR__ . '/db_test.php';

exit(T::summary());
