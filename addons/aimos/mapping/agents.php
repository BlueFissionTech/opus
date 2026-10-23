<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'aimos',
    'agents' => [
        'addon.aimos' => [
            'mode' => 'specialist',
            'description' => 'Provides package-owned specialist capabilities.',
            'profile' => 'aimos.specialist',
            'tools' => ['aimos.get'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
