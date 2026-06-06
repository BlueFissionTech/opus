<?php

declare(strict_types=1);

use BlueFission\Jenerator\Parsing\JenssParser;
use BlueFission\Jenerator\Runtime\Interpreter;
use BlueFission\Jenerator\Runtime\Io\CollectingIo;

$root = dirname(__DIR__, 2);
$manifestPath = __DIR__ . DIRECTORY_SEPARATOR . 'runtime-contract-proof.json';
$strict = in_array('--strict', $argv, true);
$parseOnly = in_array('--parse-only', $argv, true);

$autoload = getenv('JENERATOR_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}

if (!is_file($autoload)) {
    fwrite(STDERR, "Jenerator autoload not found. Set JENERATOR_AUTOLOAD to a Composer autoload file that provides BlueFission\\Jenerator.\n");
    exit(2);
}

require_once $autoload;

foreach ([JenssParser::class, Interpreter::class, CollectingIo::class] as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Required Jenerator class is unavailable: {$class}\n");
        exit(2);
    }
}

$manifest = readJson($manifestPath);
$scripts = $manifest['scripts'] ?? [];
if (!is_array($scripts) || $scripts === []) {
    fwrite(STDERR, "No JenSS scripts are listed in the proof manifest.\n");
    exit(1);
}

$parser = new JenssParser();
$failures = 0;
$gaps = 0;

foreach ($scripts as $script) {
    if (!is_array($script)) {
        continue;
    }

    $relativePath = (string) ($script['path'] ?? '');
    $mode = (string) ($script['mode'] ?? 'execute');
    $required = (bool) ($script['required'] ?? true);
    $scriptPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

    try {
        if (!is_file($scriptPath)) {
            throw new RuntimeException("Script not found: {$relativePath}");
        }

        $ast = $parser->parseFile($scriptPath);
        $messages = [];

        if (!$parseOnly && $mode !== 'parse') {
            $io = new CollectingIo();
            $interpreter = new Interpreter($io);
            $interpreter->run($ast);
            $messages = $io->messages();
        }

        $messageCount = count($messages);
        echo "[ok] {$relativePath} ({$mode}, messages={$messageCount})\n";
    } catch (Throwable $e) {
        $line = "[gap] {$relativePath}: {$e->getMessage()}";
        if ($required || $strict) {
            $failures++;
            $line = "[fail] {$relativePath}: {$e->getMessage()}";
        } else {
            $gaps++;
        }
        echo $line . "\n";
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
    if (!is_file($path)) {
        throw new RuntimeException("Manifest not found: {$path}");
    }

    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("Unable to read manifest: {$path}");
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Manifest is not valid JSON: {$path}");
    }

    return $decoded;
}
