<?php

declare(strict_types=1);

namespace App\Registration;

use App\Business\Presentation\PackageTheme;
use App\Business\Services\ApplicationProfile;
use App\Business\Services\RuntimePathResolver;
use App\Business\Services\SelectedAddOnManager;
use App\Business\Services\VibeThemeRenderer;
use BlueFission\BlueCore\Auth;
use BlueFission\BlueCore\IExtension;
use BlueFission\Connections\Database\MySQLLink;
use BlueFission\Data\Storage\MySQL;
use BlueFission\Data\Storage\Session;
use BlueFission\Data\Storage\Storage;

final class ProfileRegistration implements IExtension
{
    public function name(): string
    {
        return 'Application Profile';
    }

    public function init(): void
    {
        $app = \App::instance();
        $profile = ApplicationProfile::current();
        $app->webRoot(SITE_ROOT);
        $app->delegate('session', new Session());
        $app->bind(Storage::class, MySQL::class);
        $app->bindArgs(['session' => new Session()], Auth::class);
        $app->bindArgs(['config' => $app->configuration('database')['mysql']], MySQLLink::class);
        if ($profile->name === 'dashboard') {
            $paths = $GLOBALS['OPUS_RUNTIME_PATHS'] ?? RuntimePathResolver::discover();
            $app->addTheme(new PackageTheme('app/profile', $paths->themeRoot('profile')));
            $app->delegate('template', new VibeThemeRenderer());
        }
        if ($profile->addons !== []) {
            $manager = $app->getDynamicInstance(SelectedAddOnManager::class);
            $manager->loadSelected($profile->addons);
        }
    }
}
