<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'meridia',
    'agents' => [
        'addon.meridia' => [
            'mode' => 'generated',
            'description' => 'Explains permitted route candidates without invoking or activating them.',
            'profile' => 'meridia.specialist',
            'tools' => [],
            'imports' => [],
            'exports' => [],
            'permissions' => ['meridia.read'],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
