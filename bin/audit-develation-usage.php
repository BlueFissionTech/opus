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
} catch (Throwable $error) {
    $reason = in_array($error->getMessage(), ['inventory_tokenizer_unavailable', 'inventory_root_unavailable'], true)
        ? $error->getMessage() : 'inventory_failed';
    fwrite(STDERR, $reason . PHP_EOL);
    exit(1);
}
