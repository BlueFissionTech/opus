<?php

declare(strict_types=1);

namespace App\Business\Console;

use App\Business\Console\BotMan\CommandLineDriver;
use App\Business\Services\AgentCommandContextProvider;
use App\Business\Services\CommandHost;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\Ref;
use BlueFission\Services\Service;
use BlueFission\Str;
use BotMan\BotMan\BotMan;
use LengthException;
use RuntimeException;
use Throwable;

class CliManager extends Service
{
    public const MAX_COMMAND_BYTES = 65536;
    public const MAX_CONFIRMATION_BYTES = 128;
    private AgentCommandContextProvider $contextProvider;

    public function __construct(
        private CommandHost $commandHost,
        ?AgentCommandContextProvider $contextProvider = null
    ) {
        $this->contextProvider = $contextProvider ?? new AgentCommandContextProvider();
        parent::__construct();
    }

    public function cmd(): int
    {
        $input = defined('STDIN') ? Ref::resource(STDIN) : Ref::open('php://stdin');
        $output = defined('STDOUT') ? Ref::resource(STDOUT) : Ref::open('php://output', ['mode' => 'w']);
        $error = defined('STDERR') ? Ref::resource(STDERR) : Ref::open('php://stderr', ['mode' => 'w']);
        try {
            return $this->interact($input, $output, $error);
        } finally {
            $input->close();
            $output->close();
            $error->close();
        }
    }

    /** Host stream boundary; caller-owned handles remain open. */
    public function interact(Ref $input, Ref $output, Ref $error): int
    {
        $exitCode = 0;
        try {
            if (!$input->valid() || !$output->valid() || !$error->valid()) {
                throw new RuntimeException('cli_stream_unavailable');
            }
            $this->emit($output, "Type your message. Type '.' on a line by itself when you're done.\n");
            while (($nextLine = $this->line($input, self::MAX_COMMAND_BYTES)) !== false) {
                $nextLine = Str::make($nextLine)->trim()->val();
                if ($nextLine === '.') { break; }
                if ($nextLine === '') { continue; }

                $presentation = $this->execute($nextLine);
                $this->write($presentation, $output, $error);
                $exitCode = $presentation->exitCode();
                if ($presentation->failedAtHost()) { return $exitCode; }

                if ($presentation->confirmationRequired()) {
                    $token = (string) $presentation->continuationToken();
                    if (Str::isEmpty(Str::trim($token))) {
                        $this->emit($error, "cli_confirmation_unavailable\n");
                        return 2;
                    }
                    try {
                        $this->emit($output, 'Proceed? [y/N] ');
                        $answer = $this->line($input, self::MAX_CONFIRMATION_BYTES);
                    } catch (Throwable $failure) {
                        // No prefix or IO failure is consent. Let Wise consume its token once.
                        $this->resume($token, false);
                        throw $failure;
                    }
                    $approved = $answer !== false
                        && Arr::has(['y', 'yes'], Str::make($answer)->trim()->lower()->val(), true);
                    $presentation = $this->resume($token, $approved);
                    $this->write($presentation, $output, $error);
                    $exitCode = $presentation->exitCode();
                    if ($presentation->confirmationRequired()) {
                        $this->emit($error, "cli_confirmation_unresolved\n");
                        return 2;
                    }
                    if ($answer === false || $presentation->failedAtHost()) { return $exitCode; }
                }
            }
        } catch (Throwable $failure) {
            $tooLong = $failure instanceof LengthException;
            try {
                $this->emit($error, $tooLong ? "cli_input_too_long\n" : "cli_stream_failed\n");
            } catch (Throwable) {
                // A failed diagnostic stream must not start another command or expose IO details.
            }
            return $tooLong ? 2 : 1;
        }
        return $exitCode;
    }

    private function line(Ref $input, int $maximum): string|false
    {
        // Ref owns reading/EOF semantics. Distinguish a maximum-size CRLF record from overflow.
        $line = $input->line($maximum + 3);
        if ($line === false) {
            if (!$input->eof()) { throw new RuntimeException('cli_stream_failed'); }
            return false;
        }
        $line = Str::make($line);
        $terminated = $line->endsWith("\n");
        if ($terminated) {
            $line->val($line->sub(0, -1));
            if ($line->endsWith("\r")) { $line->val($line->sub(0, -1)); }
        }
        // The transport limit is bytes, not Unicode characters or trimmed content.
        if (strlen($line->val()) > $maximum) { throw new LengthException('cli_input_too_long'); }
        if (!$terminated && !$input->eof()) {
            // A nonblocking/partial read is not a complete command or consent record.
            throw new RuntimeException('cli_stream_incomplete');
        }
        return $line->val();
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

    private function emit(Ref $stream, string $text): void
    {
        if ($stream->write($text) !== strlen($text)) {
            throw new RuntimeException('cli_stream_failed');
        }
    }

    private function write(CommandPresentation $presentation, Ref $output, Ref $error): void
    {
        if (Str::isNotEmpty($presentation->outputText())) {
            $this->emit($output, PHP_EOL . $presentation->outputText() . PHP_EOL . PHP_EOL);
        }

        if (Str::isNotEmpty($presentation->diagnosticText())) {
            $this->emit($error, $presentation->diagnosticText() . PHP_EOL);
        }
    }
}
