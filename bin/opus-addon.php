<?php

declare(strict_types=1);

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;
use BlueFission\Arr;
use BlueFission\Str;

$runtimePaths = require dirname(__DIR__) . '/common/bootstrap/runtime.php';

$command = $argv[1] ?? '';

if ($command === 'generate') {
    $name = $argv[2] ?? '';
    $target = $argv[3] ?? '';
    $namespace = $argv[4] ?? null;
    $result = (new AddOnScaffoldService($runtimePaths->hostRoot()))->generate($name, $target, $namespace);
    print Str::make(Arr::make($result)->toJson())->append(PHP_EOL)->val();
    exit($result['created'] ? 0 : 1);
}

if ($command === 'validate') {
    $target = $argv[2] ?? '';
    $result = (new AddOnContractValidator())->validate($target);
    print Str::make(Arr::make($result)->toJson())->append(PHP_EOL)->val();
    exit($result['valid'] ? 0 : 1);
}

fwrite(STDERR, "Usage:\n  php bin/opus-addon.php generate <name> <target> [namespace]\n  php bin/opus-addon.php validate <target>\n");
exit(2);
