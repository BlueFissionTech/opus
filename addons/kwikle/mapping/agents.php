<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'kwikle',
    'agents' => [
        'addon.kwikle' => [
            'mode' => 'specialist',
            'description' => 'Provides package-owned specialist capabilities.',
            'profile' => 'kwikle.specialist',
            'tools' => ['kwikle-workflow.get', 'kwikle-workflow.do'],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
