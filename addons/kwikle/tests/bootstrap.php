<?php
declare(strict_types=1);
$loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->addPsr4('AddOns\\Kwikle\\', dirname(__DIR__) . '/logic/');
