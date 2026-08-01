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

$manifest = Arr::make(readJson($manifestPath));
$scripts = Arr::make($manifest->get('scripts'));
if ($scripts->isEmpty()) {
    fwrite(STDERR, "No JenSS scripts are listed in the proof manifest.\n");
    exit(1);
}

$parser = new JenssParser();
$failures = 0;
$gaps = 0;

foreach ($scripts as $script) {
    if (!Arr::is($script)) {
        $failures++;
        echo "[fail] Script entry is not an object.\n";
        continue;
    }

    $script = Arr::make($script);
    $relativePath = (string) ($script->get('path') ?? '');
    $mode = (string) ($script->get('mode') ?? 'execute');
    $required = !$script->hasKey('required') || (bool) $script->get('required');
    $normalizedPath = Str::make($relativePath)
        ->replace('/', DIRECTORY_SEPARATOR)
        ->replace('\\', DIRECTORY_SEPARATOR)
        ->val();
    $scriptPath = $root . DIRECTORY_SEPARATOR . $normalizedPath;

    try {
        if (!FileSystem::fileExists($scriptPath)) {
            throw new RuntimeException("Script not found: {$relativePath}");
        }

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
    echo "[fail] Required runtime contract scripts failed: {$failures}\n";
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
