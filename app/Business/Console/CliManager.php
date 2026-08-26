<?php

declare(strict_types=1);

namespace App\Business\Console;

use App\Business\Console\BotMan\CommandLineDriver;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\WiseCommandHost;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Str;
use BotMan\BotMan\BotMan;

class CliManager extends Service
{
    public function __construct(
        private WiseCommandHost $commandHost,
        private AgentCommandContextProvider $contextProvider
    ) {
        parent::__construct();
    }

    public function cmd(): int
    {
        print "Type your message. Type '.' on a line by itself when you're done.\n";

        $input = fopen('php://stdin', 'r');
        $exitCode = 0;

        while (is_resource($input)) {
            $nextLine = fgets($input, 1024);
            if ($nextLine === false) {
                break;
            }

            $nextLine = Str::make($nextLine)->trim()->val();
            if ($nextLine === '.') {
                break;
            }

            $presentation = $this->execute($nextLine);
            $this->write($presentation);
            $exitCode = $presentation->exitCode();

            if ($presentation->confirmationRequired()
                && Str::isNotEmpty((string) $presentation->continuationToken())
            ) {
                print 'Proceed? [y/N] ';
                $answer = Str::make((string) fgets($input, 16))->trim()->lower()->val();
                $presentation = $this->resume(
                    (string) $presentation->continuationToken(),
                    Arr::has(['y', 'yes'], $answer)
                );
                $this->write($presentation);
                $exitCode = $presentation->exitCode();
            }
        }

        return $exitCode;
    }

    public function execute(string|array $input, array $context = []): CommandPresentation
    {
        $context = Arr::isNotEmpty($context)
            ? $context
            : $this->contextProvider->forActor('cli');

        return $this->commandHost->execute($input, $context);
    }

    public function resume(string $token, bool $approved, array $context = []): CommandPresentation
    {
        $context = Arr::isNotEmpty($context)
            ? $this->contextProvider->forContinuation($context)
            : $this->contextProvider->forActor('cli');

        return $this->commandHost->resume($token, $approved, $context);
    }

    public function chat(): void
    {
        print "Type your message. Type '.' on a line by itself when you're done.\n";
        echo '> ';
        $app = \App::instance();
        $botman = $app->service('botman');

        $driver = $botman->getDriver();
        $driver->setBotMan($botman);

        $input = fopen('php://stdin', 'r');
        while (is_resource($input)) {
            printf("%c%c", 0x08, 0x08);
            echo "\e[0m> ";

            $nextLine = fgets($input, 1024);
            if ($nextLine === false) {
                break;
            }

            $nextLine = Str::make($nextLine)->trim()->val();
            if ($nextLine === '.') {
                break;
            }

            $this->setBotMessage($botman, $nextLine);
            $botman->listen();
        }
    }

    public function setBotMessage(BotMan $botman, string $nextLine): void
    {
        $driver = $botman->getDriver();
        if ($driver instanceof CommandLineDriver) {
            $incomingMessage = new \BotMan\BotMan\Messages\Incoming\IncomingMessage(
                $nextLine,
                'cli',
                'cli'
            );
            $driver->setMessage($incomingMessage);
        }
    }

    private function write(CommandPresentation $presentation): void
    {
        if (Str::isNotEmpty($presentation->outputText())) {
            echo PHP_EOL . $presentation->outputText() . PHP_EOL . PHP_EOL;
        }

        if (Str::isNotEmpty($presentation->diagnosticText())) {
            fwrite(STDERR, $presentation->diagnosticText() . PHP_EOL);
        }
    }
}
