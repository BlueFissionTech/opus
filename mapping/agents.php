<?php

declare(strict_types=1);

return [
    'version' => 1,
    'owner' => 'application',
    'agents' => [
        'opus.central' => [
            'mode' => 'central',
            'description' => 'Coordinates application tasks through a bounded command surface.',
            'profile' => 'opus.central',
            'tools' => [
                'command.list',
                'command.get',
                'command.help',
                'feature.list',
                'feature.show',
                'feature.more',
                'feature.help',
                'info.get',
                'info.help',
                'calc.do',
                'calc.help',
                'howto.find',
                'howto.show',
                'howto.help',
                'resource.list',
                'resource.show',
                'resource.more',
                'resource.help',
            ],
            'imports' => [],
            'exports' => [],
            'permissions' => [],
            'lifecycle' => [
                'states' => ['active'],
            ],
        ],
    ],
];
