<?php

declare(strict_types=1);

use Opus\Tools\ComposerVcsAudit;

$root = dirname(__DIR__);
require_once $root . '/tools/ComposerVcsAudit.php';

try {
    $result = (new ComposerVcsAudit())->auditFiles(
        $root . '/composer.json',
        $root . '/composer.lock',
        $root . '/templates/composer/opus-root.json'
    );
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($result['errors'] as $error) {
    fwrite(STDERR, '[fail] ' . $error . PHP_EOL);
}

if ($result['errors'] !== []) {
    exit(1);
}

echo '[ok] Blue Fission VCS registry covers '
    . count($result['packages'])
    . ' recursively required packages; DevElation remains Packagist-backed.'
    . PHP_EOL;
