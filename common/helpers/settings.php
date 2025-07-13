<?php

// TODO: set this in a config file
date_default_timezone_set('America/New_York');

if (!defined("APP_ROOT") ){
	define('APP_ROOT', dirname(dirname(dirname(__FILE__))).'/');	
}
if (!defined("PROJECT_ROOT") ){
	define('PROJECT_ROOT', APP_ROOT . 'core');
}
if (!defined("SITE_ROOT") ){
	define('SITE_ROOT', APP_ROOT.'public');	
}
if (!defined("DEBUG") ){
	define('DEBUG', false);
}
if (!defined('STDIN')) {
  define('STDIN', fopen('php://stdin', 'r'));
}
// Some error handling to be removed later
ini_set('display_errors', 1);
ini_set('html_errors', 1);
ini_set("error_log", APP_ROOT."storage/error.log");
error_reporting(E_ALL);
set_time_limit(3000);

if(file_exists( APP_ROOT.'.env' )) {
 	import_env_vars( APP_ROOT.'.env' );
}