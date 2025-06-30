<?php

if(!function_exists('import_env_vars')) {
	function import_env_vars( $file ) {
		$variables = file($file);
		foreach ($variables as $var) {
			putenv(trim($var));
			list($name, $value) = explode("=", $var);
			$_ENV[$name] = $value;
		}
	}
}

if(!function_exists('env')) {
  function env($key, $default = null)
  {
      $value = getenv($key);

      if ($value === false) {
          return $default;
      }
      return $value;
  }
}