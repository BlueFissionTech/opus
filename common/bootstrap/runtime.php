<?php

declare(strict_types=1);

use App\Business\Services\RuntimePathResolver;

$packageRoot = dirname(__DIR__, 2);
require_once $packageRoot . '/app/Business/Services/RuntimePathResolver.php';

$configuredHostRoot = getenv('OPUS_HOST_ROOT');
$activeAutoloader = $GLOBALS['_composer_autoload_path'] ?? null;
$runtimePaths = RuntimePathResolver::discover(
    $packageRoot,
    $configuredHostRoot !== false && $configuredHostRoot !== '' ? $configuredHostRoot : null,
    is_string($activeAutoloader) ? $activeAutoloader : null
);

require_once $runtimePaths->autoloadPath();
$GLOBALS['OPUS_RUNTIME_PATHS'] = $runtimePaths;

return $runtimePaths;
