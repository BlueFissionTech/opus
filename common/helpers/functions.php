<?php

if(!function_exists('import_env_vars')) {
	function import_env_vars( $file ) {
		return \App\Business\Services\EnvironmentLoader::import($file);
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
		return \App\Business\Services\ProjectPathResolver::resolve(
			$pathInProject,
			APP_ROOT,
			PROJECT_ROOT
		);
	}
}
