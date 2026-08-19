<?php
namespace App\Business\Console;

use BotMan\BotMan\BotMan;
use BlueFission\Services\Service;
use BlueFission\Str;
use App\Business\Console\BotMan\CommandLineDriver;
// use BlueFission\Wise\Cmd\CommandProcessor;

class CliManager extends Service {

	// protected $_processor;

	public function __construct()
    {
		parent::__construct();
		// $this->_processor = $commandProcessor;
	}

	public function cmd()
	{
		$core = instance('core');
		print "Type your message. Type '.' on a line by itself when you're done.\n";

		$fp = fopen('php://stdin', 'r');
		$last_line = false;
		$message = '';
		while (!$last_line) {
		    $next_line = Str::trim((string) fgets($fp, 1024)); // read the special file to get the user input from keyboard
		    if ("." == $next_line) {
		      $last_line = true;
		    } else {
		      $message .= $next_line;

		      if ( $next_line == 'chat' ) {
		      	$convo = instance('convo');
		      	$transcript = $convo->generateRecentDialogueText(1000, 30000);
		      	echo $transcript;
		      } elseif ( $next_line == 'history' ) {
		      	$thread = instance('thread');
		      	$transcript = $thread->history();
		      	echo $transcript;
		      } else {
			      // $response = $this->_processor->process($next_line);
			      $core->handle($next_line);
			      $response = $core->output();
			      
			      echo "\n$response\n\n";
			  }
		    }
		}
	}

	public function chat()
	{
		print "Type your message. Type '.' on a line by itself when you're done.\n";
		echo '> ';
	    $app = \App::instance();
	    $botman = $app->service('botman');

	    $driver = $botman->getDriver();
	    $driver->setBotMan($botman);

	    $fp = fopen('php://stdin', 'r');
	    $last_line = false;
	    while (!$last_line) {
        	printf("%c%c",0x08,0x08);
			echo "\e[0m> ";

	        $next_line = Str::trim((string) fgets($fp, 1024)); // read the special file to get the user input from keyboard
	        if ("." == $next_line) {
	            $last_line = true;
	        } else {
	            $this->setBotMessage($botman, $next_line);
	            $botman->listen();
	        }
		}
	}

	public function setBotMessage($botman, $next_line)
	{
	    $driver = $botman->getDriver();
	    if ($driver instanceof CommandLineDriver) {
	        $incomingMessage = new \BotMan\BotMan\Messages\Incoming\IncomingMessage($next_line, 'cli', 'cli');
	        $driver->setMessage($incomingMessage);
	    }
	}
}
