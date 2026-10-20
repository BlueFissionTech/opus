<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'tributary',
    'agents' => [
        'addon.tributary' => [
            'mode' => 'specialist',
            'description' => 'Provides package-owned specialist capabilities.',
            'profile' => 'tributary.specialist',
            'tools' => ['tributary-task.get', 'tributary-task.do'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
