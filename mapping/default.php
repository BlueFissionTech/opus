<?php
use BlueFission\Services\Mapping;
use BlueFission\Net\HTTP;
use BlueFission\BlueCore\Business\Managers\ContentManager;

Mapping::add('/game', function() {
	return template('default', 'interactive.html', ['title'=>"Welcome", 'name'=>env('APP_NAME')]);
}, 'index', 'get');

Mapping::add('/chatwindow', function() {
	return template('chatwindow.html', ['name'=>env('APP_NAME')]);
}, 'chatwindow', 'get');

// Conversations
Mapping::add('/parse', ['BlueFission\BlueCore\Business\Managers\ConversationManager', 'parse'], 'parse', 'get');
Mapping::add('/history', function() {
	/*
	$thread = instance('thread');
	$prompt = null !== store('aiPrompt') ? store('aiPrompt')['prompt'] : "You are a very clever and helpful bot designed to assist people in building enterprise machine learning web applications and consulting them in best practices. Your key features are curiosity and an I've-got-this attitude!";
	$thread->setPrompt( $prompt );
	$xml = $thread->history();

	$dom = new \DOMDocument('1.0');
	$dom->preserveWhiteSpace = true;
	$dom->formatOutput = true;
	$dom->loadXML($xml);
	$xml_pretty = $dom->saveXML();

	echo $xml_pretty;
	*/
	echo "Dialogue\n\n";
	$convo = instance('convo');
	$transcript = $convo->generateRecentDialogueText(1000, 30000);
	die(var_dump($transcript));
});

Mapping::add('/command', function() {
	$processor = instance()->getDynamicInstance(BlueFission\BlueCore\Command\CommandProcessor::class);

	$result = $processor->process('help with news');

	var_dump($result);
});

Mapping::add('/clear', function() {
	$convo = instance('convo');
	$convo->clearConversation();
	
	echo "All clear!";
});

Mapping::add('/fillin', function() {
	// $topic = "The future of work and AI/Automation - labor concerns and observations from dawn of the GPT era.";
	$topic = "I think that the dystopian predictions around AI are sensationalist fear mongering and a distraction from the real conversation about capitalism.";
	$result = (new \BlueFission\Automata\LLM\FillIn(new \App\Business\Services\OpenAIService(), (new \App\Business\Prompts\SocialPostPrompt($topic))->prompt()))->run(['max_tokens'=>250]);
	var_dump($result); // echos: "Modern AI - from sci fi to reality"
});

Mapping::add('/chain', function() {
	$llm = new \App\Business\Services\OpenAIService();
	$agent = new \BlueFission\Automata\LLM\Agent($llm);

	// Register the tools
	// $agent->registerTool("Stock DB", new \BlueFission\Automata\LLM\Tools\StockDatabase());
	$agent->registerTool("Stock DB", new \BlueFission\Automata\LLM\Tools\Weather());
	$agent->registerTool("Search", new \BlueFission\Automata\LLM\Tools\WebSearch());
	$agent->registerTool("Calculator", new \BlueFission\Automata\LLM\Tools\Calculator());

	// Execute a question
	$result = $agent->execute("What is the multiplication of the ratio between stock prices for 'ABC' and 'XYZ' in January 3rd and the ratio between the same stock prices in January the 4th?");
});


Mapping::add('/', function() {
	return template('default', 'default.html', ['title'=>"Welcome", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'index', 'get');

// ADDED JULY 3/4 UPDATE
Mapping::add('/opus', function() {
	return template('default', 'opus.html', ['title'=>"Opus", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'opus', 'get');
Mapping::add('/services', function() {
	return template('default', 'services.html', ['title'=>"BlueFission Services", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'services', 'get');
Mapping::add('/about', function() {
	return template('default', 'about.html', ['title'=>"About BlueFission", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'about', 'get');
Mapping::add('/blog', function() {
	return template('default', 'blog.html', ['title'=>"Blog", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'blog', 'get');
Mapping::add('/blog-single', function() {
	return template('default', 'blog-single.html', ['title'=>"Blog Post", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'blog_single', 'get');
Mapping::add('/contact', function() {
	return template('default', 'contact.html', ['title'=>"Contact BlueFission", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'contact', 'get');
Mapping::add('/terms', function() {
	return template('default', 'terms.html', ['title'=>"Terms and Conditions", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'terms', 'get');
Mapping::add('/privacy', function() {
	return template('default', 'privacy.html', ['title'=>"Privacy Policy", 'name'=>env('APP_NAME'), 'csrf_token'=>HTTP::session('_token')]);
}, 'privacy', 'get');






Mapping::add('/test', function() {
	$object = new BlueFission\Obj();
	$value = new BlueFission\Num("25.0");
	// $value = new BlueFission\Str("test");

	$object->behavior(BlueFission\Behavioral\Behaviors\Event::CHANGE, function($event, $args) {
		echo("I changed!");
	});
	$object->num = $value;
	$object->num = 40;
	echo $object->num;
});

// Authentication
Mapping::add('/login', ['App\Business\Http\LoginController', 'login'], 'login', 'get');
Mapping::add('/register', ['App\Business\Http\LoginController', 'registration'], 'register', 'get');
Mapping::add('/forgotpassword', ['App\Business\Http\LoginController', 'forgotpassword'], 'forgotpassword', 'get');

// Admin
Mapping::add('/admin', ['App\Business\Http\AdminController', 'index'], 'admin', 'get');
Mapping::add('/admin/register', ['App\Business\Http\AdminController', 'registration'], 'admin.register', 'get');
Mapping::add('/admin/forgotpassword', ['App\Business\Http\AdminController', 'forgotpassword'], 'admin.forgotpassword', 'get');

Mapping::add('/admin/modules/dashboard', ['App\Business\Http\AdminController', 'dashboard'], 'admin.dashboard', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/users', ['App\Business\Http\AdminController', 'users'], 'admin.users', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/addons', ['App\Business\Http\AdminController', 'addons'], 'admin.addons', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/terminal', ['App\Business\Http\AdminController', 'terminal'], 'admin.terminal', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/content', ['App\Business\Http\AdminController', 'content'], 'admin.content', 'get')->gateway('admin:auth');
Mapping::add('/admin/modules/media', ['App\Business\Http\AdminController', 'media'], 'admin.media', 'get')->gateway('admin:auth');

// Dynamic Pages
ContentManager::map();