<?php
use BlueFission\BlueCore\Engine as App;
use App\Business\Console\CliManager;
use App\Business\Console\UserManager;
use App\Business\Console\DatabaseManager;
use App\Business\Console\AddOnManager;
use App\Business\Console\RuntimeContractManager;

if ( !defined('STDIN') ) return;

$app = App::instance();

$app->delegate('cmd', CliManager::class);
$app->register('cmd', 'i', 'cmd');
$app->register('cmd', 't', 'chat');

$app->delegate('user', UserManager::class);
$app->register('user', 'create', 'create');
$app->register('user', 'passwd', 'changePassword');

$app->delegate('database', DatabaseManager::class);
$app->register('database', 'delta', 'runMigrations');
$app->register('database', 'revert', 'revertMigrations');
$app->register('database', 'populate', 'populate');

$app->delegate('addon', AddOnManager::class);
$app->register('addon', 'install', 'install');
$app->register('addon', 'install-all', 'install_all');
$app->register('addon', 'uninstall', 'uninstall');
$app->register('addon', 'activate', 'activate');
$app->register('addon', 'activate-all', 'activate_all');
$app->register('addon', 'deactivate', 'deactivate');
$app->register('addon', 'show', 'showAll');

$app->delegate('contract', RuntimeContractManager::class);
$app->register('contract', 'proof', 'proof');
$app->register('contract', 'targets', 'targets');
$app->register('contract', 'validate', 'validate');

// $app->delegate('code', CodeManager::class );
// $app->register('code', 'generate', 'generate');
