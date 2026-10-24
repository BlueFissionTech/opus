<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'keryx',
    'agents' => [
        'addon.keryx' => [
            'mode' => 'specialist',
            'description' => 'Provides package-owned specialist capabilities.',
            'profile' => 'keryx.specialist',
            'tools' => ['keryx.get'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
