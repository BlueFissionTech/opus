<?php

declare(strict_types=1);

use App\Business\Http\ProfileController;
use App\Business\Services\ApplicationProfile;
use BlueFission\Services\Mapping;

$profile = ApplicationProfile::current();
if (!$profile->minimal()) {
    return;
}

Mapping::add('/health', [ProfileController::class, 'health'], 'profile.health', 'get');
Mapping::add('/ready', [ProfileController::class, 'ready'], 'profile.ready', 'get');
Mapping::add('/api/info', [ProfileController::class, 'info'], 'profile.info', 'get');
if ($profile->name === 'dashboard') {
    Mapping::add('/dashboard', [ProfileController::class, 'dashboard'], 'profile.dashboard', 'get')->gateway('auth');
    Mapping::add('/login', [ProfileController::class, 'login'], 'profile.login', 'get');
    Mapping::add('/login', ['App\Business\Http\Api\AuthenticationController', 'login'], 'profile.authenticate', 'post');
    Mapping::add('/logout', [ProfileController::class, 'logout'], 'profile.logout', 'post')->gateway('auth');
}
