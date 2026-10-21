<?php

declare(strict_types=1);

namespace AddOns\Meridia\Registration;

final class AddOnRegistration
{
    // Opus calls this once on Engine OnAppLoaded for an active, validated package.
    public function contributions(): array
    {
        return [
            'api' => require dirname(__DIR__, 2) . '/mapping/api.php',
            'app' => require dirname(__DIR__, 2) . '/mapping/app.php',
            'console' => require dirname(__DIR__, 2) . '/mapping/console.php',
            'default' => require dirname(__DIR__, 2) . '/mapping/default.php',
            'agents' => require dirname(__DIR__, 2) . '/mapping/agents.php',
            'menus' => require dirname(__DIR__, 2) . '/mapping/menus.php',
        ];
    }
}
