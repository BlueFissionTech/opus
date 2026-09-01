<?php

declare(strict_types=1);

return [
    'catalog' => [
        'id' => 'opus.default',
        'version' => 1,
        'source' => 'package',
    ],
    'settings' => [
        'mode' => 'shadow',
        'capture_unknown_intents' => true,
        'capture_private_conversations' => false,
        'capture_provider_payloads' => false,
        'promotion_requires_review' => true,
    ],
    'classifier' => [
        'enabled' => true,
        'force_retrain' => false,
        'test_split' => 0.2,
        'minimum_samples' => 2,
        'minimum_labels' => 2,
        'cache_required' => true,
    ],
    'learning' => [
        'automatic_observation' => false,
        'trusted_artifacts_only' => true,
        'serialized_state_enabled' => false,
        'scope_isolation_required' => true,
    ],
    'review' => [
        'candidate_status' => 'pending',
        'promotion_requires_approval' => true,
        'generated_fallbacks_are_training_data' => false,
        'persistence_required' => true,
    ],
    'intents' => [
        'opus.command.discovery' => [
            'command' => 'command.list',
            'examples' => [
                'what can you do',
                'show available commands',
                'list the commands I can use',
            ],
        ],
        'opus.command.help' => [
            'command' => 'command.help',
            'examples' => [
                'help me use the command environment',
                'how do commands work',
                'show command help',
            ],
        ],
        'opus.profile.todos' => [
            'command' => 'todo.list',
            'examples' => [
                'show my todo list',
                'what tasks are in my profile',
                'list my private todos',
            ],
        ],
        'opus.profile.notes' => [
            'command' => 'note.list',
            'examples' => [
                'show my notes',
                'list my private notes',
                'what notes have I saved',
            ],
        ],
        'opus.profile.calendar' => [
            'command' => 'schedule.list',
            'examples' => [
                'show my calendar',
                'list my schedule',
                'what is on my calendar',
            ],
        ],
        'opus.profile.goals' => [
            'command' => 'step.list',
            'examples' => [
                'show my current goal and steps',
                'list my next steps',
                'what goal am I working on',
            ],
        ],
    ],
    'fallbacks' => [
        'unknown.intent' => [
            'response' => 'I could not match that request to an available command.',
            'next_command' => 'command.help',
            'capture_candidate' => true,
        ],
        'profile.denied' => [
            'response' => 'That profile resource is not available in this context.',
            'capture_candidate' => false,
        ],
    ],
];
