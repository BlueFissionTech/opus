<?php

declare(strict_types=1);

$host = dirname(__DIR__, 3);
if (!is_file($host . '/vendor/autoload.php') || !is_file($host . '/app/Business/Services/AgentDelegationService.php')) {
    throw new RuntimeException('Run package tests within a compatible Opus source checkout.');
}
$loader = require $host . '/vendor/autoload.php';
$loader->addPsr4('AddOn\\Tributary\\', dirname(__DIR__) . '/logic/');
