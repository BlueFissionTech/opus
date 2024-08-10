<?php
namespace App\Business\Managers;

use BlueFission\Services\Service;

class DatasourceManager extends Service {

	private $_deltaDir = OPUS_ROOT.'/datasources/structure/';
	private $_generatorDir = OPUS_ROOT.'/datasources/generator/';

	public function __construct( )
    {
		parent::__construct();
	}

	public function setDeltaDirectory( $directory )
	{
		$this->_deltaDir = $directory;
	}

	public function setGeneratorDirectory( $directory )
	{
		$this->_generatorDir = $directory;
	}

	public function runMigrations()
	{
		$deltas = $this->loadDeltas();
		foreach ( $deltas as $delta ) {
			$classname = '';
			if ( strpos($delta, '.') != 0 ) {
				$classname = $this->findClassName( $this->_deltaDir . $delta );
				if ( $classname ) {
					include_once($this->_deltaDir . $delta);
					// $object = new $classname();
					$object = \App::makeInstance($classname);
					call_user_func([$object, 'change']);
				}
			}
		}
	}

	public function revertMigrations()
	{
		$deltas = $this->loadDeltas(1);
		foreach ( $deltas as $delta ) {
			$classname = '';
			if ( strpos($delta, '.') != 0 ) {
				$classname = $this->findClassName( $this->_deltaDir . $delta );
				if ( $classname ) {
					include_once($this->_deltaDir . $delta);
					// $object = new $classname();
					$object = \App::makeInstance($classname);
					call_user_func([$object, 'revert']);
				}
			}
		}
	}

	public function populate()
	{
		$generators = $this->loadGenerators();
		if ( in_array('RootSeeder.php', $generators) ) {
			$classname = $this->findClassName( $this->_generatorDir . 'RootSeeder.php' );
			if ( $classname ) {
				include_once($this->_generatorDir . 'RootSeeder.php');
				$object = \App::makeInstance($classname);
				if ( method_exists($object, 'seeders') ) {
					$generators = call_user_func([$object, 'seeders']);
					array_walk($generators, function(&$generator) {
						$generator = $generator.'.php';
					});
				}
			}
		}

		foreach ( $generators as $generator ) {
			$classname = '';

			if ( strpos($generator, '.') !== 0 ) {
				$generator = strpos($generator, '.php') ? $generator : $generator . '.php';
				
				$classname = $this->findClassName( $this->_generatorDir . $generator );
				if ( $classname ) {
					include_once($this->_generatorDir . $generator);
					$object = \App::makeInstance($classname);
					call_user_func([$object, 'populate']);
				}
			}
		}
	}

	private function loadGenerators()
	{
		return scandir($this->_generatorDir);
	}

	private function loadDeltas( $reverse = SCANDIR_SORT_ASCENDING )
	{
		return scandir($this->_deltaDir, $reverse);
	}

	// https://stackoverflow.com/questions/7153000/get-class-name-from-file
	private function findClassName( $file )
	{
		$fp = fopen($file, 'r');
		$class = $namespace = $buffer = '';
		$i = 0;
		while (!$class) {
		    if (feof($fp)) break;

		    $buffer .= fread($fp, 512);
		    $tokens = token_get_all($buffer);

		    if (strpos($buffer, '{') === false) continue;

		    for (;$i<count($tokens);$i++) {
		        if ($tokens[$i][0] === T_NAMESPACE) {
		            for ($j=$i+1;$j<count($tokens); $j++) {
		                if ($tokens[$j][0] === T_STRING) {
		                     $namespace .= '\\'.$tokens[$j][1];
		                } else if ($tokens[$j] === '{' || $tokens[$j] === ';') {
		                     break;
		                }
		            }
		        }

		        if ($tokens[$i][0] === T_CLASS) {
		            for ($j=$i+1;$j<count($tokens);$j++) {
		                if ($tokens[$j] === '{') {
		                    $class = $tokens[$i+2][1];
		                }
		            }
		        }
		    }
		}

		return $class;
	}
}