<?php
// ProcessesCommandMiddleware.php
namespace App\Business\Middleware;

use App\Business\Services\AgentCommandContextProvider;
use BotMan\BotMan\BotMan;
use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\Wise\Cmd\ICommandProcessor;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use BlueFission\Str;
use BotMan\BotMan\Interfaces\Middleware\Received;
use BotMan\BotMan\Interfaces\Middleware\Sending;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;

class ProcessesCommandMiddleware implements Received, Sending
{
    protected $commandManager;
    protected $commandProcessor;
    private AgentCommandContextProvider $contextProvider;
    // protected $_core;

    public function __construct(
        CommandManager $commandManager,
        ICommandProcessor $commandProcessor,
        ?AgentCommandContextProvider $contextProvider = null
    )
    // public function __construct()
    {
        $this->commandManager = $commandManager;
        $this->commandProcessor = $commandProcessor;
        $this->contextProvider = $contextProvider ?? new AgentCommandContextProvider();
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
        $user = $bot->getUser();
        $actorId = is_object($user) && method_exists($user, 'getId')
            ? Str::make((string) $user->getId())->trim()->val()
            : '';
        $context = $this->contextProvider->forActor($actorId);
        $command = $this->commandProcessor->process(new CommandRequest($walkerResults, context: $context));
        
        if ($command->confirmationRequired()) {
            $description = $this->confirmationDescription($command);
            $question = Question::create("Do you want to proceed with this command: {$description}?")
                ->addButtons([
                    Button::create('Yes')->value('yes'),
                    Button::create('No')->value('no'),
                ]);

            $token = $command->continuationToken();
            if (Str::isNotEmpty((string) $token)) {
                $bot->ask($question, function (IncomingMessage $response) use ($token, $context): void {
                    $this->resumeCommand(
                        (string) $token,
                        $response->getValue() === 'yes',
                        $context
                    );
                });
            }
        }
    }

    protected function resumeCommand(string $token, bool $approved, array $context): CommandResult
    {
        return $this->commandProcessor->process(CommandRequest::resume($token, $approved, $context));
    }

    protected function confirmationDescription(CommandResult $result): string
    {
        return Str::make((string) ($result->command()?->description() ?? $result->description()))
            ->trim()
            ->val();
    }

    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return $regexMatched;
    }
}
