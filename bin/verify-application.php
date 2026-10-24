<?php

declare(strict_types=1);

use Opus\Tools\ApplicationEndpointVerifier;

if (($argv[1] ?? '') === '--help') {
    echo "Usage: php bin/verify-application.php <http(s)://origin[:port]> <api|dashboard>\n";
    echo "Read-only public endpoint qualification; not a deployment or tenant-readiness approval.\n";
    exit(0);
}
if ($argc !== 3) {
    fwrite(STDERR, "Expected an approved origin and profile; use --help.\n");
    exit(2);
}

$outputLevel = ob_get_level();
ob_start();
set_error_handler(static function (int $severity): bool {
    throw new ErrorException('probe_unavailable', 0, $severity);
});
try {
    require dirname(__DIR__) . '/common/bootstrap/runtime.php';
    require_once dirname(__DIR__) . '/tools/ApplicationEndpointVerifier.php';
    $result = (new ApplicationEndpointVerifier())->verify($argv[1], $argv[2]);
    if (ob_get_length() > 0) {
        $result = ['version' => 1, 'ok' => false, 'reason' => 'probe_unavailable'];
    }
} catch (InvalidArgumentException) {
    $result = ['version' => 1, 'ok' => false, 'reason' => 'invalid_arguments'];
} catch (Throwable) {
    $result = ['version' => 1, 'ok' => false, 'reason' => 'probe_unavailable'];
} finally {
    while (ob_get_level() > $outputLevel) {
        ob_end_clean();
    }
    restore_error_handler();
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
