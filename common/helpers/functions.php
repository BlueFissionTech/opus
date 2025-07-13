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

if(!function_exists('resolve_path')) {
	function resolve_path($pathInProject)
	{
	    $rootPath = rtrim(APP_ROOT, DIRECTORY_SEPARATOR);
	    $projectPath = rtrim(PROJECT_ROOT, DIRECTORY_SEPARATOR);

	    $candidate = $rootPath . DIRECTORY_SEPARATOR . $pathInProject;
	    $fallback = $projectPath . DIRECTORY_SEPARATOR . $pathInProject;

	    if (file_exists($candidate)) {
	        return $candidate;
	    }

	    return $fallback;
	}
}