<?php

declare(strict_types=1);

use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AddOnScaffoldService;

require dirname(__DIR__) . '/vendor/autoload.php';

$command = $argv[1] ?? '';

if ($command === 'generate') {
    $name = $argv[2] ?? '';
    $target = $argv[3] ?? '';
    $namespace = $argv[4] ?? null;
    $result = (new AddOnScaffoldService())->generate($name, $target, $namespace);
    print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['created'] ? 0 : 1);
}

if ($command === 'validate') {
    $target = $argv[2] ?? '';
    $result = (new AddOnContractValidator())->validate($target);
    print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['valid'] ? 0 : 1);
}

fwrite(STDERR, "Usage:\n  php bin/opus-addon.php generate <name> <target> [namespace]\n  php bin/opus-addon.php validate <target>\n");
exit(2);
