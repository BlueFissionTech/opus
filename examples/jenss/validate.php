<?php

declare(strict_types=1);

use BlueFission\Jenerator\Parsing\JenssParser;
use BlueFission\Jenerator\Runtime\Interpreter;
use BlueFission\Jenerator\Runtime\Io\CollectingIo;
use BlueFission\Arr;
use BlueFission\Data\FileSystem;
use BlueFission\Str;

$root = dirname(__DIR__, 2);
$manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime-contract-proof.json';
$autoload = getenv('JENERATOR_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}

if (!is_file($autoload)) {
    fwrite(STDERR, "Jenerator autoload not found. Set JENERATOR_AUTOLOAD to a Composer autoload file that provides BlueFission\\Jenerator.\n");
    exit(2);
}

require_once $autoload;

$arguments = Arr::make($argv);
$strict = $arguments->has('--strict', true);
$parseOnly = $arguments->has('--parse-only', true);

foreach (Arr::make([JenssParser::class, Interpreter::class, CollectingIo::class]) as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Required Jenerator class is unavailable: {$class}\n");
        exit(2);
    }
}

try {
    $manifest = Arr::make(readJson($manifestPath));
} catch (Throwable $error) {
    fwrite(STDERR, "[fail] {$error->getMessage()}\n");
    exit(1);
}

$scripts = Arr::make($manifest->get('scripts'));
if ($scripts->isEmpty()) {
    fwrite(STDERR, "No JenSS scripts are listed in the proof manifest.\n");
    exit(1);
}

$failures = 0;
$gaps = 0;
$fixture = $manifest->get('fixture');
if (!Str::is($fixture) || Str::make($fixture)->trim()->isEmpty()) {
    $failures++;
    echo "[fail] Fixture path is missing.\n";
} else {
    $fixture = Str::make($fixture)->trim()->val();
    $normalizedFixture = Str::make($fixture)
        ->replace('/', DIRECTORY_SEPARATOR)
        ->replace('\\', DIRECTORY_SEPARATOR)
        ->val();
    if (!FileSystem::fileExists($root . DIRECTORY_SEPARATOR . $normalizedFixture)) {
        $failures++;
        echo "[fail] Fixture not found: {$fixture}\n";
    }
}

$parser = new JenssParser();

foreach ($scripts as $script) {
    if (!Arr::is($script)) {
        $failures++;
        echo "[fail] Script entry is not an object.\n";
        continue;
    }

    $script = Arr::make($script);
    $relativePath = $script->get('path');
    if (!Str::is($relativePath) || Str::make($relativePath)->trim()->isEmpty()) {
        $failures++;
        echo "[fail] Script entry is missing a string path.\n";
        continue;
    }
    $relativePath = Str::make($relativePath)->trim()->val();
    $mode = $script->get('mode') ?? 'execute';
    if (
        !Str::is($mode)
        || !Arr::make(['parse', 'execute'])->has($mode, true)
    ) {
        $failures++;
        echo "[fail] {$relativePath}: unsupported script mode.\n";
        continue;
    }

    $required = $script->get('required') ?? true;
    if (!is_bool($required)) {
        $failures++;
        echo "[fail] {$relativePath}: required must be boolean.\n";
        continue;
    }

    $normalizedPath = Str::make($relativePath)
        ->replace('/', DIRECTORY_SEPARATOR)
        ->replace('\\', DIRECTORY_SEPARATOR)
        ->val();
    $scriptPath = $root . DIRECTORY_SEPARATOR . $normalizedPath;

    if (!FileSystem::fileExists($scriptPath)) {
        $failures++;
        echo "[fail] Script not found: {$relativePath}\n";
        continue;
    }

    try {
        $ast = $parser->parseFile($scriptPath);
        $messages = Arr::make([]);

        if (!$parseOnly && $mode !== 'parse') {
            $io = new CollectingIo();
            $interpreter = new Interpreter($io);
            $interpreter->run($ast);
            $messages = Arr::make($io->messages());
        }

        $messageCount = $messages->count();
        echo "[ok] {$relativePath} ({$mode}, messages={$messageCount})\n";
    } catch (Throwable $e) {
        if ($required || $strict) {
            $failures++;
            echo "[fail] {$relativePath}: {$e->getMessage()}\n";
        } else {
            $gaps++;
            echo "[gap] {$relativePath}: {$e->getMessage()}\n";
        }
    }
}

if ($gaps > 0) {
    echo "[info] Optional target gaps: {$gaps}\n";
}

if ($failures > 0) {
    echo "[fail] Runtime contract validation failed: {$failures}\n";
    exit(1);
}

echo "[ok] Runtime contract proof validation completed.\n";
exit(0);

function readJson(string $path): array
{
    if (!FileSystem::fileExists($path)) {
        throw new RuntimeException("Manifest not found: {$path}");
    }

    $contents = FileSystem::fileContents($path);
    if ($contents === null) {
        throw new RuntimeException("Unable to read manifest: {$path}");
    }

    $decoded = json_decode($contents, true);
    if (!Arr::is($decoded)) {
        throw new RuntimeException("Manifest is not valid JSON: {$path}");
    }

    return $decoded;
}
