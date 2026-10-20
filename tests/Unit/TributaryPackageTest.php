<?php

declare(strict_types=1);

// Run the source package's same tests under the host's locked dependencies.
require_once dirname(__DIR__, 2) . '/packages/tributary/tests/bootstrap.php';
require_once dirname(__DIR__, 2) . '/packages/tributary/tests/TaskServiceTest.php';
