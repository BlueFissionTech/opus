<?php
namespace BlueFission\Framework;

use BlueFission\Services\Application;
use BlueFission\Services\Service;
use BlueFission\Services\Response;
use BlueFission\Behavioral\Behaviors\Event;
use BlueFission\Utils\Loader;

/**
 * Class Engine
 *
 * The Engine class is a subclass of Application that sets up and starts the BlueFission application.
 *
 * @package BlueFission\Framework
 */
class Engine extends Application {

	/**
	 * The loader object
	 *
	 * @var Loader
	 */
	private $_loader;

	/**
	 * An array of registered extensions
	 *
	 * @var array
	 */
	private $_extensions = [];

	/**
	 * An array of configurations for the application
	 *
	 * @var array
	 */
	private $_configurations = [];

	/**
	 * Bootstraps the application, loading configurations and auto-discovering helpers and mappings
	 *
	 * @return Engine
	 */
	public function bootstrap() {

		// $this->handleRoutes();

		$this->_loader = Loader::instance();

		$this->boost(new Event('OnAppInitialized'));

		$this->loadConfiguration();

		$this->autoDiscoverHelpers();

		$this->autoDiscoverMapping();

		$this->boost(new Event('OnAppLoaded'));
		
		return $this;
	}

	/**
	 * Loads the database and application configurations
	 *
	 * @return void
	 */
	public function loadConfiguration() {

		// Data

		$database = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'database.php';

		$this->_configurations['database'] = $database;

		// Communication

		$communication = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'communication.php';

		$this->_configurations['communication'] = $communication;

		// Natural Langauge Processing
		
		$grammar = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'nlp'.DIRECTORY_SEPARATOR.'grammar.php';
		$dictionary = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'nlp'.DIRECTORY_SEPARATOR.'dictionary.php';
		$roots = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'nlp'.DIRECTORY_SEPARATOR.'roots.php';
		$dialogue = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'nlp'.DIRECTORY_SEPARATOR.'dialogue.php';
		$statements = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'nlp'.DIRECTORY_SEPARATOR.'statements.php';

		$this->_configurations['nlp'] = [
			'grammar' => $grammar,
			'dictionary' => $dictionary,
			'roots' => $roots,
			'dialogue' => $dialogue,
			'statements' => $statements,
		];

		// Machine Learning

		$machinelearning = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'machinelearning.php';

		$this->_configurations['machinelearning'] = $machinelearning;

		// Application Logic

		$config = require dirname( dirname( dirname( __FILE__ ) ) ).DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'application.php';

		$this->_configurations['app'] = $config;

		foreach ( $config['aliases'] as $alias=>$classname ) {
			class_alias($classname, $alias);
		}

		foreach ( $config['extensions'] as $extension ) {

			$class = new $extension();
			$class->init();
			$this->addExtension( $class->name() );
		}

		foreach ( $config['gateways'] as $name=>$gateway ) {
			$this->gateway($name, $gateway);
		}
	}

	/**
	 * Returns the configuration values for a specific key or all configuration values
	 *
	 * @param string $key The key for the configuration values to return
	 *
	 * @return mixed The configuration values for the specified key or all configuration values
	 */
	public function configuration(string $key = '')
	{
		if ( $key ) {
			return $this->_configurations[$key];
		}
		return $this->_configurations;
	}

	public function command( $service, $behavior, $data = null, $callback = null )
	{
		$result = null;
		$module = $this->_services[$service];
		
		// $module = instance($service);

		if ( $module ) {
			$module->message($behavior, $data);
			$module = instance($service);
			if (method_exists($module, 'response')) {
				$result = $module->response();
			} else if (method_exists($module, 'status')) {
				$result = $module->status();
			}

			if ( $callback ) {
				return $callback($result);
			}
		} else {
			return $callback("Resource not found");
		}
		return;
	}

	/**
	 * Automatically discover the mapping files and load them.
	 * 
	 * @return void
	 */
	private function autoDiscoverMapping() {
		$this->_loader->load("mapping.*");
	}

	/**
	 * Automatically discover the helper files and load them.
	 * 
	 * @return void
	 */
	private function autoDiscoverHelpers() {
		$this->_loader->load("common.helpers.*");
	}

	/**
	 * Add an extension to the extensions array.
	 * 
	 * @param string $extension The name of the extension to add.
	 * 
	 * @return void
	 */
	private function addExtension( $extension ) {
		$this->_extensions[] = $extension;
	}

	public function getAbilities()
    {
        $abilities = [];

        foreach ($this->_routes as $behaviorName => $senders) {

            foreach ($senders as $senderName => $recipients) {
            	foreach ( $recipients as $recipient ) {
            		$service = $recipient['recipient'];
	                if (!isset($abilities[$service])) {
	                    $abilities[$service] = [];
	                }

	                if (!in_array($behaviorName, $abilities[$service])) {
	                    $abilities[$service][] = $behaviorName;
	                }
	            }
            }
        }

        return $abilities;
    }
}