<?php
namespace App\Registration;
// use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\BlueCore\Business\Managers\NavMenuManager;
use BlueFission\BlueCore\Business\Managers\DatasourceManager;
use BlueFission\BlueCore\Business\Managers\AddOnManager;
use BlueFission\BlueCore\Business\Managers\InterpretationManager;
use BlueFission\BlueCore\Business\Managers\CommunicationManager;
use BlueFission\BlueCore\Business\Managers\ConversationManager;
use BlueFission\BlueCore\Business\Managers\ThreadManager;
use BlueFission\BlueCore\Business\Managers\SkillManager;
use App\Business\Services\OpenAIService;
use App\Business\MysqlConnector;
use BlueFission\Automata\LLM\Clients\OpenAIClient;
use BlueFission\Automata\LLM\Clients\GoogleGeminiClient;
use BlueFission\Automata\Intent\Matcher;
use BlueFission\BlueCore\Core;
use BlueFission\BlueCore\Skill\Intent\Matcher;
use BlueFission\BlueCore\Theme;
use BlueFission\BlueCore\IExtension;
// For Conversations
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Cache\SymfonyCache;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

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
		// Check if running in command line or via HTTP
		$isCommandLine = (php_sapi_name() === 'cli');
		// Register the appropriate driver based on the environment
		if ($isCommandLine) {
			DriverManager::loadDriver(\App\Business\Console\BotMan\CommandLineDriver::class);
		} else {
		    DriverManager::loadDriver(\BotMan\Drivers\Web\WebDriver::class);
		}
		$adapter = new FilesystemAdapter();
		$cache = new SymfonyCache($adapter);
		$botman = BotManFactory::create([], $cache);
		$this->delegate('botman', $botman);

		// $this->delegate('core', Core::class);
		$this->delegate('communication', CommunicationManager::class);
		// $this->delegate('cmd', CommandManager::class);
		$this->delegate('nav', NavMenuManager::class);
		$this->delegate('addons', AddOnManager::class);
		$this->delegate('interpreter', InterpretationManager::class);
		$this->delegate('convo', ConversationManager::class);
		$this->delegate('thread', ThreadManager::class);
		$this->delegate('skill', SkillManager::class);
		$this->delegate('datasource', DatasourceManager::class);

		$this->delegate('intentmatcher', Matcher::class);
		$this->delegate('mysql', MysqlConnector::class);
		$this->delegate('openai', OpenAIService::class);
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

		$this->bind('BlueFission\BlueCore\Domain\Content\Queries\IAllContentQuery', 'BlueFission\BlueCore\Domain\Content\Queries\AllContentQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Content\Repositories\IContentRepository', 'BlueFission\BlueCore\Domain\Content\Repositories\ContentRepositorySql');

		$this->bind('BlueFission\BlueCore\Domain\Communication\Repositories\ICommunicationRepository', 'BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Communication\Queries\IUndeliveredCommunicationsQuery', 'BlueFission\BlueCore\Domain\Communication\Queries\UndeliveredCommunicationsQuerySql');

		$this->bind('BlueFission\Data\Storage\Storage', 'BlueFission\Data\Storage\MySQL');

		$this->bind('BlueFission\Automata\LLM\Clients\IClient', OpenAIClient::class);
		$this->bind('BlueFission\Automata\Analysis\IAnalyzer', 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\ITopicRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\TopicRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IDialoguesByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\DialoguesByTopicQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IDialoguesByKeywordsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\DialoguesByKeywordsQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByKeywordQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByKeywordQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByTimestampQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByTimestampQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByUserIdQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByUserIdQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IDialogueTypeRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\DialogueTypeRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IDialogueRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\DialogueRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IMessageRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\MessageRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\ILanguageRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\LanguageRepositorySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\ITopicRoutesByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\TopicRoutesByTopicQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\ITagsByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\TagsByTopicQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IAllTopicsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\AllTopicsQuerySql');
		$this->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IFactsByKeywordsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\FactsByKeywordsQuerySql');
	}

	/**
	 * Pass arguments to different components
	 */
	public function arguments() {
		$this->bindArgs( ['session'=>new \BlueFission\Data\Storage\Session()], 'App\Business\Http\AdminController');
		$this->bindArgs( ['session'=>new \BlueFission\Data\Storage\Session()], 'BlueFission\Services\Authenticator');

		$this->bindArgs( ['config'=>$this->configuration('database')['mysql']], 'BlueFission\Connections\Database\MySQLLink');
		$this->bindArgs( ['driverConfigurations'=>$this->configuration('communication')['drivers']], 'App\Business\Managers\CommunicationManager');
		$this->bindArgs( [
				'rules'=>$this->configuration('nlp')['grammar']['rules'],
				'commands'=>$this->configuration('nlp')['grammar']['commands'],
				'tokens'=>$this->configuration('nlp')['dictionary']
			], 'BlueFission\Automata\Language\Grammar');
		$this->bindArgs( ['modelDirPath'=>$this->configuration('paths')['ml']['models']], 'BlueFission\Automata\Analysis\KeywordTopicAnalyzer');
		$this->bindArgs( ['modelDirPath'=>$this->configuration('paths')['ml']['models']], 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
		$this->bindArgs( ['config'=>$this->configuration('nlp')['roots']], 'BlueFission\Automata\Language\StemmerLemmatizer');
		$this->bindArgs( ['storage'=>new \BlueFission\Data\Storage\Session(['location'=>'cache','name'=>'system'])], 'BlueFission\Wise\Cmd\CommandProcessor');
		$this->bindArgs( ['apiKey'=>env('OPEN_AI_API_KEY')], OpenAIClient::class);
		$this->bindArgs( ['apiKey'=>env('GOOGLE_GEMINI_API_KEY')], GoogleGeminiClient::class);
	}

	public function addons()
	{
		$addons = $this->service('addons');
		$addons->loadActivatedAddOns();
	}

	public function themes()
	{
		$this->theme(new Theme('app/default', 'default'));
		$this->theme(new Theme('app/admin', 'admin'));
		$this->theme(new Theme('app/bluefission', 'bluefission'));
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

	private function service($name) {
		return $this->_app->service($name);
	}

	private function configuration($name) {
		return $this->_app->configuration($name);
	}

	private function theme($theme) {
		$this->_app->addTheme($theme);
	}
}