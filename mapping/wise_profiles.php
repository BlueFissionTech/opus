<?php

declare(strict_types=1);

return [
    'version' => 1,
    'resources' => [
        'todos' => [
            'tool' => 'todo',
            'aliases' => ['task'],
            'adapter' => 'available',
            'operations' => [
                'read' => ['list', 'open', 'select', 'find', 'help'],
                'write' => ['make', 'add', 'edit', 'delete'],
            ],
        ],
        'notes' => [
            'tool' => 'note',
            'adapter' => 'available',
            'operations' => [
                'read' => ['list', 'get', 'find', 'next', 'previous', 'help'],
                'write' => ['make', 'save'],
            ],
        ],
        'functions' => [
            'tool' => 'function',
            'adapter' => 'upstream_required',
            'operations' => [
                'read' => ['list', 'get', 'help'],
                'write' => ['make', 'edit', 'delete'],
                'execute' => ['run'],
            ],
        ],
        'calendars' => [
            'tool' => 'schedule',
            'adapter' => 'available',
            'operations' => [
                'read' => ['list', 'next', 'previous', 'help'],
                'write' => ['add', 'edit', 'delete'],
            ],
        ],
        'goals' => [
            'tool' => 'step',
            'adapter' => 'combined',
            'operations' => [
                'read' => ['list', 'show', 'get', 'previous', 'next', 'help'],
                'write' => ['generate', 'update', 'set', 'add', 'delete'],
            ],
        ],
        'steps' => [
            'tool' => 'step',
            'adapter' => 'combined',
            'operations' => [
                'read' => ['list', 'show', 'get', 'previous', 'next', 'help'],
                'write' => ['generate', 'update', 'set', 'add', 'delete'],
            ],
        ],
    ],
    'roles' => [
        'user' => [
            'own' => ['*.*'],
            'delegated' => [],
        ],
        'agent.central' => [
            'own' => ['*.*'],
            'delegated' => ['*.read', '*.write', '*.execute'],
        ],
        'agent.specialist' => [
            'own' => ['*.*'],
            'delegated' => ['*.read', '*.write', '*.execute'],
        ],
        'administrator' => [
            'own' => ['*.*'],
            'delegated' => ['*.*'],
        ],
    ],
];
