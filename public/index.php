<?php
/**
 * This script requires and initializes the autoloader, sets up the
 * Loader utility, starts a session, sets a CSRF token in the session,
 * and runs the BlueFission Framework Engine.
 */

use BlueFission\Utils\Loader;
use BlueFission\Utils\Util;
use BlueFission\BlueCore\Engine as App;
use App\Domain\User\AuthenticationRequired;
use App\Business\Presentation\AuthenticationFailureResponse;
use App\Business\Services\ApplicationProfile;
use App\Business\Services\ProfileBootstrap;

// Resolve the active host autoloader before loading application settings.
$runtimePaths = require dirname(__DIR__) . '/common/bootstrap/runtime.php';
require $runtimePaths->packageRoot() . '/common/helpers/functions.php';
require $runtimePaths->packageRoot() . '/common/helpers/settings.php';

// Require the autoloader for non-composer based scripts
// Initialize the Loader utility for non-composer compatible scripts
$loader = Loader::instance();
$loader->addPath($runtimePaths->hostRoot());
$loader->addPath($runtimePaths->packageRoot());
$loader->addPath($runtimePaths->hostRoot() . DIRECTORY_SEPARATOR . "mod");
$loader->addPath($runtimePaths->hostRoot() . DIRECTORY_SEPARATOR . "app");

// Initialize the BlueFission Framework Engine and run it
$runApplication = static function (): void {
    try {
        session_start();
        if (empty(store('_token'))) {
            store('_token', csrf_token());
        }
        $app = App::instance()->bootstrap();
        if (ApplicationProfile::requested()) {
            if (ob_get_length() > 0) {
                throw new RuntimeException('application_bootstrap_output_invalid');
            }
            $boundary = new ProfileBootstrap();
            if (!$boundary->routeAllowed($app) || !$boundary->csrfAllowed()) {
                return;
            }
        }
        $app->args()->process()->validateCsrf()->run();
    } catch (AuthenticationRequired) {
        (new AuthenticationFailureResponse())->send();
    }
};

if (ApplicationProfile::requested()) {
    (new ProfileBootstrap())->run($runApplication);
} else {
    $runApplication();
}
