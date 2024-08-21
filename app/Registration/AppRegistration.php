<?php
namespace App\Registration;
// use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\BlueCore\Business\Managers\NavMenuManager;
use BlueFission\BlueCore\Business\Managers\DatasourceManager;
use BlueFission\BlueCore\Business\Managers\AddOnManager;
use BlueFission\BlueCore\Business\Managers\SkillManager;
use App\Business\MysqlConnector;
use BlueFission\Automata\LLM\Clients\OpenAIClient;
use BlueFission\Automata\LLM\Clients\GoogleGeminiClient;
use BlueFission\Data\Storage\Session;
use BlueFission\Automata\Intent\Matcher;
use BlueFission\BlueCore\Core;
use BlueFission\BlueCore\Theme;
use BlueFission\BlueCore\IExtension;

/**
 * Class AppRegistration
 *
 * This class contains logic for registering different components and dependencies in the application
 *
 * @package App\Registration
 */
class AppRegistration implements IExtension {
	/**
	 * The instance of the app
	 *
	 * @var object
	 */
	private $_app;
	
	/**
	 * The name of the app
	 *
	 * @var string
	 */
	private $_name = "Application Main";
	
	/**
	 * AppRegistration constructor.
	 */
	public function __construct() {
		$this->_app = \App::instance();
	}

	/**
	 * Get the name of the app
	 *
	 * @return string
	 */
	public function name() {
		return $this->_name;
	}

	/**
	 * Initialize the registrations
	 */
	public function init() {
		$this->bindings();
		$this->arguments();
		$this->registrations();
		$this->themes();
		$this->addons();
	}

	/**
	 * Register different components in the app
	 */
	public function registrations() {
		

		// $this->delegate('core', Core::class);
		$this->delegate('session', Session::class);
		// $this->delegate('cmd', CommandManager::class);
		$this->delegate('nav', NavMenuManager::class);
		$this->delegate('addons', AddOnManager::class);
		$this->delegate('skill', SkillManager::class);
		$this->delegate('datasource', DatasourceManager::class);

		$this->delegate('intentmatcher', Matcher::class);
		$this->delegate('mysql', MysqlConnector::class);
	}

	/**
	 * Bind different components with their respective implementations
	 */
	public function bindings() {
		$this->bind('App\Domain\User\Queries\IAllUsersQuery', 'App\Domain\User\Queries\AllUsersQuerySql');
		$this->bind('App\Domain\User\Queries\IAllCredentialStatusesQuery', 'App\Domain\User\Queries\AllCredentialStatusesQuerySql');
		$this->bind('App\Domain\User\Repositories\IUserRepository', 'App\Domain\User\Repositories\UserRepositorySql');
		$this->bind('App\Domain\User\Repositories\ICredentialRepository', 'App\Domain\User\Repositories\CredentialRepositorySql');

		$this->bind('BlueFission\BlueCore\Domain\AddOn\Queries\IAllAddOnsQuery', 'BlueFission\BlueCore\Domain\AddOn\Queries\AllAddOnsQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery', 'BlueFission\BlueCore\Domain\AddOn\Queries\ActivatedAddOnsQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\AddOn\Repositories\IAddOnRepository', 'BlueFission\BlueCore\Domain\AddOn\Repositories\AddOnRepositorySql');

		$this->bind('BlueFission\Data\Storage\Storage', 'BlueFission\Data\Storage\MySQL');

		$this->bind('BlueFission\Automata\LLM\Clients\IClient', OpenAIClient::class);
		$this->bind('BlueFission\Automata\Analysis\IAnalyzer', 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
	}

	/**
	 * Pass arguments to different components
	 */
	public function arguments() {
		$this->bindArgs( ['session'=>new Session()], 'App\Business\Http\AdminController');
		$this->bindArgs( ['session'=>new Session()], 'BlueFission\BlueCore\Auth');

		$this->bindArgs( ['config'=>$this->configuration('database')['mysql']], 'BlueFission\Connections\Database\MySQLLink');

		$this->bindArgs( ['modelDirPath'=>$this->configuration('paths')['ml']['models']], 'BlueFission\Automata\Analysis\KeywordTopicAnalyzer');
		$this->bindArgs( ['modelDirPath'=>$this->configuration('paths')['ml']['models']], 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
		
		$this->bindArgs( ['storage'=>new Session(['location'=>'cache','name'=>'system'])], 'BlueFission\Wise\Cmd\CommandProcessor');
		$this->bindArgs( ['apiKey'=>env('OPEN_AI_API_KEY')], OpenAIClient::class);
		$this->bindArgs( ['apiKey'=>env('GOOGLE_GEMINI_API_KEY')], GoogleGeminiClient::class);
	}

	public function addons()
	{
		$addons = instance('addons');
		$addons->loadActivatedAddOns();
	}

	public function themes()
	{
		$this->theme(new Theme('app/default', 'default'));
		$this->theme(new Theme('app/admin', 'admin'));
	}

	// Helpers
	private function delegate($name, $class) {
		$this->_app->delegate($name, $class);
	}

	private function bind($abstract, $concrete) {
		$this->_app->bind($abstract, $concrete);
	}

	private function bindArgs($args, $class) {
		$this->_app->bindArgs($args, $class);
	}

	private function configuration($name) {
		return $this->_app->configuration($name);
	}

	private function theme($theme) {
		$this->_app->addTheme($theme);
	}
}