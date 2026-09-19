<?php

declare(strict_types=1);

use Opus\Tools\InstalledDependencyAudit;

require_once dirname(__DIR__) . '/tools/InstalledDependencyAudit.php';

$arguments = array_slice($argv, 1);
$unknown = array_diff($arguments, ['--no-dev']);
if ($unknown !== []) {
    fwrite(STDERR, "Usage: php bin/audit-installed-dependencies.php [--no-dev]\n");
    exit(2);
}

try {
    $root = dirname(__DIR__);
    $report = (new InstalledDependencyAudit())->auditFiles(
        $root . '/composer.lock',
        $root . '/vendor/composer/installed.json',
        !in_array('--no-dev', $arguments, true)
    );
} catch (Throwable) {
    echo json_encode(['schema_version' => 1, 'status' => 'unavailable',
        'reason' => 'dependency_metadata_invalid_or_unavailable'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(2);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($report['status'] === 'pass' ? 0 : 1);
