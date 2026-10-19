<?php

declare(strict_types=1);

use Opus\Tools\DevElationUsageInventory;

require_once dirname(__DIR__) . '/tools/DevElationUsageInventory.php';

try {
    $report = (new DevElationUsageInventory())->audit(dirname(__DIR__));
    if (in_array('--summary', $argv, true)) {
        unset($report['files']);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report['complete'] ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "inventory_failed\n");
    exit(1);
}
