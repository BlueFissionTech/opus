<?php

declare(strict_types=1);

$autoloaders = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        $loader = require $autoloader;
        $loader->addPsr4('AddOns\\Keryx\\', dirname(__DIR__) . '/logic/');
        return;
    }
}

throw new RuntimeException('Composer autoloader could not be resolved.');
