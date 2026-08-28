<?php

declare(strict_types=1);

namespace App\Business\Middleware;

use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\WiseCommandHost;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\BlueCore\Business\Managers\CommandManager;
use BlueFission\Str;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Interfaces\Middleware\Received;
use BotMan\BotMan\Interfaces\Middleware\Sending;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\Actions\Button;
use BotMan\BotMan\Messages\Outgoing\Question;

class ProcessesCommandMiddleware implements Received, Sending
{
    public function __construct(
        private CommandManager $commandManager,
        private WiseCommandHost $commandHost,
        private ?AgentCommandContextProvider $contextProvider = null
    ) {
        $this->contextProvider ??= new AgentCommandContextProvider();
    }

    public function received(IncomingMessage $message, $next, BotMan $bot)
    {
        $message->addExtras('command', $this->commandManager->parse($message->getText()));

        return $next($message);
    }

    public function sending($payload, $next, BotMan $bot)
    {
        $message = $bot->getMessage();
        $walkerResults = $message->getExtras('command');

        if ($message->getExtras('command_processed') === true) {
            return $next($payload);
        }

        if (Arr::is($walkerResults) && Arr::getPath($walkerResults, 'subject') === 'app') {
            $message->addExtras('command_processed', true);

            return $next($payload)->then(function ($response) use ($bot, $walkerResults) {
                $this->dispatchCommand($bot, $walkerResults);

                return $response;
            });
        }

        return $next($payload);
    }

    protected function dispatchCommand(BotMan $bot, array $command): CommandPresentation
    {
        $user = $bot->getUser();
        $actorId = is_object($user) && method_exists($user, 'getId')
            ? Str::make((string) $user->getId())->trim()->val()
            : '';
        $context = $this->contextProvider->forActor($actorId);
        $presentation = $this->commandHost->execute($command, $context);

        if (!$presentation->confirmationRequired()) {
            $this->replyWithPresentation($bot, $presentation);

            return $presentation;
        }

        $token = $presentation->continuationToken();
        if (Str::isEmpty((string) $token)) {
            $this->replyWithPresentation($bot, $presentation);

            return $presentation;
        }

        $description = $this->confirmationDescription($presentation);
        $question = Question::create("Do you want to proceed with this command: {$description}?")
            ->addButtons([
                Button::create('Yes')->value('yes'),
                Button::create('No')->value('no'),
            ]);

        $bot->ask($question, function (IncomingMessage $response) use ($bot, $token, $context): void {
            $resumed = $this->resumeCommand(
                (string) $token,
                $response->getValue() === 'yes',
                $context
            );
            $this->replyWithPresentation($bot, $resumed);
        });

        return $presentation;
    }

    protected function resumeCommand(string $token, bool $approved, array $context): CommandPresentation
    {
        return $this->commandHost->resume(
            $token,
            $approved,
            $this->contextProvider->forContinuation($context)
        );
    }

    protected function confirmationDescription(CommandPresentation $presentation): string
    {
        $description = Str::make($presentation->description())->trim()->val();

        return Str::isNotEmpty($description) ? $description : 'the requested action';
    }

    protected function replyWithPresentation(BotMan $bot, CommandPresentation $presentation): void
    {
        if (Str::isNotEmpty($presentation->message())) {
            $bot->reply($presentation->message());
        }
    }

    public function matching(IncomingMessage $message, $pattern, $regexMatched)
    {
        return $regexMatched;
    }
}
