<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'versa',
    'agents' => [
        'addon.versa' => [
            'mode' => 'generated',
            'description' => 'Discovers scoped channels and submits host-reviewed text through injected clients; delivery is unqualified.',
            'profile' => 'versa.specialist',
            'tools' => [],
            'imports' => [],
            'exports' => [],
            'permissions' => ['versa.read', 'versa.send', 'versa.notify'],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
