<?php
// ProcessesCommandMiddleware.php
namespace App\Business\Middleware;

use BotMan\BotMan\BotMan;
use BlueFission\Data\Storage\Session;
use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\Wise\Cmd\CommandProcessor;
use BotMan\BotMan\Interfaces\Middleware\Received;
use BotMan\BotMan\Interfaces\Middleware\Sending;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;

class ProcessesCommandMiddleware implements Received, Sending
{
    protected $commandManager;
    protected $commandProcessor;
    // protected $_core;

    public function __construct(CommandManager $commandManager, CommandProcessor $commandProcessor)
    // public function __construct()
    {
        $this->commandManager = $commandManager;
        $this->commandProcessor = $commandProcessor;
    }

    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        $results = $this->commandManager->parse($message->getText());
        // $core = instance('core');
        // $core->handle($message->getText());
        // $results = $core->output();
        $message->addExtras('command', $results);

        return $next($message);
    }

    public function sending($payload, $next, BotMan $bot)
    {
        $walkerResults = $bot->getMessage()->getExtras('command');

        if ($walkerResults && $walkerResults['subject'] === 'app') {
            return $next($payload)->then(function ($response) use ($bot, $walkerResults) {
                $this->logMessage($bot, $walkerResults);
            });
        }

        return $next($payload);
    }

    protected function logMessage(BotMan $bot, $walkerResults)
    {
        $command = $this->commandProcessor->process($walkerResults);
        
        if ($command->confirmationRequired()) {
            $question = Question::create("Do you want to proceed with this command: {$command->getDescription()}?")
                ->addButtons([
                    Button::create('Yes')->value('yes'),
                    Button::create('No')->value('no'),
                ]);

            $bot->ask($question, function (IncomingMessage $response) use ($bot, $command) {
                if ($response->getValue() === 'yes') {
                    // // Execute the command
                    // $this->commandProcessor->executeCommand($command);
                    // // Forward the conversation to a representative
                    // $contactManager = new ContactManager();
                    // $contactManager->forwardConversationToRepresentative($bot, $response);
                    $this->command($command);
                }
            });
        } else {
            // Automatically execute the command
            $this->command($command);
        }
    }

    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return $regexMatched;
    }
}
