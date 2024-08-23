<?php

// TODO: set this in a config file
date_default_timezone_set('America/New_York');

if (!defined("OPUS_ROOT") ){
	define('OPUS_ROOT', dirname(dirname(dirname(__FILE__))).'/');	
}
if (!defined("SITE_ROOT") ){
	define('SITE_ROOT', OPUS_ROOT.'/public');	
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
ini_set("error_log", OPUS_ROOT."/storage/error.log");
error_reporting(E_ALL);
set_time_limit(3000);


if(file_exists( OPUS_ROOT.'/.env' )) {
 	import_env_vars( OPUS_ROOT.'/.env' );
}