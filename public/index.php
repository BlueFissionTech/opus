<?php
/**
 * This script requires and initializes the autoloader, sets up the
 * Loader utility, starts a session, sets a CSRF token in the session,
 * and runs the BlueFission Framework Engine.
 */

use BlueFission\Utils\Loader;
use BlueFission\Utils\Util;
use BlueFission\BlueCore\Engine as App;

// Require the autoloader for composer-based dependencies
require '../vendor/autoload.php';
require '../common/config/settings.php';

// Require the autoloader for non-composer based scripts
// Initialize the Loader utility for non-composer compatible scripts
$loader = Loader::instance();
$loader->addPath(dirname(getcwd()));
$loader->addPath(dirname(getcwd()).DIRECTORY_SEPARATOR."app");

// Start a session
session_start();

// Set a CSRF token in the session
if (empty(store('_token'))) {
    store('_token', csrf_token());
}

// Initialize the BlueFission Framework Engine and run it
App::instance()
	->bootstrap()
	->args()
	->process()
	->validateCsrf()
	->run();