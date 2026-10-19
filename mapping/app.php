<?php

declare(strict_types=1);

use App\Business\Commands\UserResource;
use App\Business\Services\ApplicationProfile;
use App\Business\Services\WiseIntegrationGuard;
use App\Business\Services\WiseResourceCatalogRegistration;
use BlueFission\BlueCore\Engine as App;

if (ApplicationProfile::current()->minimal()) { return; }
$app = App::instance();

$app->register('skill', 'do', 'runSkill');
$app->register('skill', 'list', 'listSkills');
$app->delegate('user', UserResource::class);
$app->register('user', 'list', 'handle');
$app->register('user', 'find', 'handle');
$app->register('user', 'update', 'handle');
$app->register('user', 'help', 'handle');
$app->register('user', 'prompt', 'handle');
$app->register('user', 'get', 'handle');

// Host-owned verbs are separate from package-owned resource names and classes.
$actions = [
    'feature' => [
        'list' => 'handle',
        'show' => 'handle',
        'more' => 'handle',
        'help' => 'handle',
    ],
    'action' => [
        'do' => 'handle',
        'make' => 'handle',
        'generate' => 'handle',
        'list' => 'handle',
        'show' => 'handle',
        'next' => 'handle',
        'previous' => 'handle',
        'delete' => 'handle',
        'help' => 'handle',
    ],
    'api' => [
        'do' => 'handle',
        'make' => 'handle',
        'generate' => 'handle',
        'list' => 'handle',
        'show' => 'handle',
        'next' => 'handle',
        'previous' => 'handle',
        'delete' => 'handle',
        'help' => 'handle',
    ],
    'info' => [
        'get' => 'handle',
        'help' => 'handle',
    ],
    'calc' => [
        'do' => 'handle',
        'help' => 'handle',
    ],
    'howto' => [
        'find' => 'search',
        'show' => 'show',
        'help' => 'help',
    ],
    'weather' => [
        'get' => 'handle',
        'help' => 'handle',
    ],
    'news' => [
        'get' => 'handle',
        'find' => 'handle',
        'help' => 'handle',
        'select' => 'handle',
    ],
    'entity' => [
        'do' => 'handle',
        'list' => 'handle',
        'get' => 'handle',
        'find' => 'handle',
    ],
    'todo' => [
        'list' => 'handle',
        'make' => 'handle',
        'add' => 'handle',
        'open' => 'handle',
        'select' => 'handle',
        'edit' => 'handle',
        'previous' => 'handle',
        'next' => 'handle',
        'delete' => 'handle',
        'find' => 'handle',
        'help' => 'handle',
    ],
    'step' => [
        'generate' => 'perform',
        'update' => 'perform',
        'list' => 'perform',
        'show' => 'perform',
        'get' => 'perform',
        'set' => 'perform',
        'add' => 'perform',
        'delete' => 'perform',
        'previous' => 'perform',
        'next' => 'perform',
        'help' => 'perform',
    ],
    'schedule' => [
        'add' => 'process',
        'list' => 'process',
        'next' => 'process',
        'previous' => 'process',
        'delete' => 'process',
        'edit' => 'process',
        'help' => 'process',
    ],
    'system' => [
        'get' => 'handle',
    ],
    'variable' => [
        'show' => 'handle',
        'list' => 'handle',
        'get' => 'handle',
        'set' => 'handle',
        'find' => 'handle',
        'previous' => 'handle',
        'next' => 'handle',
        'delete' => 'handle',
        'help' => 'handle',
    ],
    'queue' => [
        'add' => 'handle',
        'get' => 'handle',
    ],
    'stack' => [
        'add' => 'handle',
        'get' => 'handle',
    ],
    'file' => [
        'make' => 'handle',
        'edit' => 'handle',
        'add' => 'handle',
        'open' => 'handle',
        'save' => 'handle',
        'delete' => 'handle',
        'list' => 'handle',
        'find' => 'handle',
        'help' => 'handle',
    ],
    'note' => [
        'make' => 'handle',
        'save' => 'handle',
        'list' => 'handle',
        'get' => 'handle',
        'find' => 'handle',
        'next' => 'handle',
        'previous' => 'handle',
        'help' => 'handle',
    ],
    'ai' => [
        'list' => 'handle',
        'show' => 'handle',
        'find' => 'handle',
        'get' => 'handle',
        'do' => 'handle',
        'help' => 'handle',
    ],
    'command' => [
        'list' => 'list',
        'get' => 'all',
        'next' => 'next',
        'previous' => 'previous',
        'help' => 'help',
    ],
    'search' => [
        'find' => 'handle',
        'get' => 'handle',
        'next' => 'handle',
        'previous' => 'handle',
        'go' => 'handle',
        'help' => 'handle',
    ],
    'website' => [
        'get' => 'browse',
        'show' => 'viewPageContent',
        'list' => 'listItems',
        'next' => 'handle',
        'previous' => 'handle',
    ],
];

return (new WiseResourceCatalogRegistration())->installMappings(
    $app,
    (string) env('WISE_INTEGRATION', WiseIntegrationGuard::REQUIRED),
    $actions
);
