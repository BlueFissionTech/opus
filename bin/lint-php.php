#!/usr/bin/env php
<?php

declare(strict_types=1);

// Dependency-free: this gate must run before Composer installation.
function opusLintProcess(array $command): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start syntax check.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output . $error];
}

try {
    $root = realpath($argv[1] ?? dirname(__DIR__));
    if ($root === false) { throw new RuntimeException('Project directory is missing.'); }
    [$status, $listing] = opusLintProcess(['git', '-C', $root, 'ls-files', '-z', '--', '*.php']);
    if ($status !== 0) { throw new RuntimeException('A readable Git checkout is required.'); }
    $files = array_values(array_filter(explode("\0", $listing), static fn ($file) => $file !== ''));
    if ($files === []) { throw new RuntimeException('No tracked PHP files were found.'); }
    $failures = 0;
    foreach ($files as $file) {
        [$status, $output] = opusLintProcess([PHP_BINARY, '-n', '-l', $root . DIRECTORY_SEPARATOR . $file]);
        if ($status !== 0) {
            $failures++;
            fwrite(STDERR, $file . ': ' . trim($output) . PHP_EOL);
        }
    }
    fwrite(STDOUT, sprintf("PHP syntax: %d files checked; %d failures.\n", count($files), $failures));
    exit($failures === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
