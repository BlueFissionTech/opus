<?php
namespace App\Registration;
// use App\Business\Managers\CommandManager;
use App\Business\Managers\NavMenuManager;
use App\Business\Managers\DatasourceManager;
use App\Business\Managers\AddOnManager;
use App\Business\Managers\InterpretationManager;
use App\Business\Managers\CommunicationManager;
use App\Business\Managers\ConversationManager;
use App\Business\Managers\ThreadManager;
use App\Business\Managers\SkillManager;
use App\Business\Services\OpenAIService;
use App\Business\Services\OpenWeatherService;
use App\Business\Services\LocationService;
use App\Business\MysqlConnector;
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
		$this->_app->delegate('botman', $botman);

		$this->_app->delegate('core', Core::class);
		$this->_app->delegate('communication', CommunicationManager::class);
		// $this->_app->delegate('cmd', CommandManager::class);
		$this->_app->delegate('nav', NavMenuManager::class);
		$this->_app->delegate('addons', AddOnManager::class);
		$this->_app->delegate('interpreter', InterpretationManager::class);
		$this->_app->delegate('convo', ConversationManager::class);
		$this->_app->delegate('thread', ThreadManager::class);
		$this->_app->delegate('skill', SkillManager::class);
		$this->_app->delegate('datasource', DatasourceManager::class);

		$this->_app->delegate('intentmatcher', Matcher::class);
		$this->_app->delegate('mysql', MysqlConnector::class);
		$this->_app->delegate('openai', OpenAIService::class);

		// TODO move these to addons
		$this->_app->delegate('openweather', OpenWeatherService::class);
		$this->_app->delegate('location', LocationService::class);
	}

	/**
	 * Bind different components with their respective implementations
	 */
	public function bindings() {
		$this->_app->bind('App\Domain\User\Queries\IAllUsersQuery', 'App\Domain\User\Queries\AllUsersQuerySql');
		$this->_app->bind('App\Domain\User\Queries\IAllCredentialStatusesQuery', 'App\Domain\User\Queries\AllCredentialStatusesQuerySql');
		$this->_app->bind('App\Domain\User\Repositories\IUserRepository', 'App\Domain\User\Repositories\UserRepositorySql');
		$this->_app->bind('App\Domain\User\Repositories\ICredentialRepository', 'App\Domain\User\Repositories\CredentialRepositorySql');

		$this->_app->bind('BlueFission\BlueCore\Domain\AddOn\Queries\IAllAddOnsQuery', 'BlueFission\BlueCore\Domain\AddOn\Queries\AllAddOnsQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery', 'BlueFission\BlueCore\Domain\AddOn\Queries\ActivatedAddOnsQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\AddOn\Repositories\IAddOnRepository', 'BlueFission\BlueCore\Domain\AddOn\Repositories\AddOnRepositorySql');

		$this->_app->bind('BlueFission\BlueCore\Domain\Content\Queries\IAllContentQuery', 'BlueFission\BlueCore\Domain\Content\Queries\AllContentQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Content\Repositories\IContentRepository', 'BlueFission\BlueCore\Domain\Content\Repositories\ContentRepositorySql');

		$this->_app->bind('BlueFission\BlueCore\Domain\Communication\Repositories\ICommunicationRepository', 'BlueFission\BlueCore\Domain\Communication\Repositories\CommunicationRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Communication\Queries\IUndeliveredCommunicationsQuery', 'BlueFission\BlueCore\Domain\Communication\Queries\UndeliveredCommunicationsQuerySql');

		$this->_app->bind('BlueFission\Data\Storage\Storage', 'BlueFission\Data\Storage\MySQL');

		$this->_app->bind('BlueFission\Automata\LLM\Clients\IClient', 'BlueFission\Automata\LLM\Clients\OpenAIClient');
		$this->_app->bind('BlueFission\Automata\Analysis\IAnalyzer', 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\ITopicRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\TopicRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IDialoguesByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\DialoguesByTopicQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IDialoguesByKeywordsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\DialoguesByKeywordsQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByKeywordQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByKeywordQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByTimestampQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByTimestampQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesByUserIdQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesByUserIdQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IMessagesQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\MessagesQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IDialogueTypeRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\DialogueTypeRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IDialogueRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\DialogueRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\IMessageRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\MessageRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Repositories\ILanguageRepository', 'BlueFission\BlueCore\Domain\Conversation\Repositories\LanguageRepositorySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\ITopicRoutesByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\TopicRoutesByTopicQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\ITagsByTopicQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\TagsByTopicQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IAllTopicsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\AllTopicsQuerySql');
		$this->_app->bind('BlueFission\BlueCore\Domain\Conversation\Queries\IFactsByKeywordsQuery', 'BlueFission\BlueCore\Domain\Conversation\Queries\FactsByKeywordsQuerySql');
	}

	/**
	 * Pass arguments to different components
	 */
	public function arguments() {
		$this->_app->bindArgs( ['config'=>$this->_app->configuration('database')['mysql']], 'BlueFission\Connections\Database\MySQLLink');
		$this->_app->bindArgs( ['driverConfigurations'=>$this->_app->configuration('communication')['drivers']], 'App\Business\Managers\CommunicationManager');
		$this->_app->bindArgs( [
				'rules'=>$this->_app->configuration('nlp')['grammar']['rules'],
				'commands'=>$this->_app->configuration('nlp')['grammar']['commands'],
				'tokens'=>$this->_app->configuration('nlp')['dictionary']
			], 'BlueFission\Automata\Language\Grammar');
		$this->_app->bindArgs( ['modelDirPath'=>$this->_app->configuration('paths')['ml']['models']], 'BlueFission\Automata\Analysis\KeywordTopicAnalyzer');
		$this->_app->bindArgs( ['modelDirPath'=>$this->_app->configuration('paths')['ml']['models']], 'BlueFission\Automata\Intent\KeywordIntentAnalyzer');
		$this->_app->bindArgs( ['config'=>$this->_app->configuration('nlp')['roots']], 'BlueFission\Automata\Language\StemmerLemmatizer');
		$this->_app->bindArgs( ['storage'=>new \BlueFission\Data\Storage\Session(['location'=>'cache','name'=>'system'])], 'BlueFission\Wise\Cmd\CommandProcessor');
		$this->_app->bindArgs( ['apiKey'=>env('OPEN_AI_API_KEY')], 'BlueFission\Automata\LLM\Clients\OpenAIClient');
	}

	public function addons()
	{
		$addons = $this->_app->service('addons');
		$addons->loadActivatedAddOns();
	}

	public function themes()
	{
		$this->_app->addTheme(new Theme('app/default', 'default'));
		$this->_app->addTheme(new Theme('app/admin', 'admin'));
		$this->_app->addTheme(new Theme('app/bluefission', 'bluefission'));
	}
}