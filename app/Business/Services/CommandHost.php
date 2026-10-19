<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Throwable;

final class CommandHost
{
    private static bool $observingResult = false;

    public function __construct(
        private ICommandProcessor $processor,
        private CommandResultPresenter $presenter
    ) {
    }

    public function process(CommandRequest|Command|array|string $request): CommandPresentation
    {
        if (self::$observingResult) {
            return new CommandPresentation(
                'failed', null, 'Commands cannot execute from result observers.', '', false, 1,
                ['command_result_observer_reentry_denied'], 'command_result_observer_reentry_denied',
                ['effects' => 'none'], null
            );
        }
        try {
            $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
            $result = $this->processor->process($request);
        } catch (Throwable) {
            return $this->observe(CommandPresentation::hostFailure('processing'), 'processing_failed');
        }
        try {
            $presentation = $this->presenter->present($result);
        } catch (Throwable) {
            return $this->observe(CommandPresentation::hostFailure('presentation'), 'presentation_failed');
        }
        return $this->observe($presentation, 'presented');
    }

    public function execute(Command|array|string $input, array $context = []): CommandPresentation
    {
        return $this->process(new CommandRequest($input, context: $context));
    }

    public function resume(
        string $continuationToken,
        bool $approved,
        array $context = []
    ): CommandPresentation {
        return $this->process(CommandRequest::resume($continuationToken, $approved, $context));
    }

    private function observe(CommandPresentation $result, string $phase): CommandPresentation
    {
        self::$observingResult = true;
        try {
            $status = $result->status();
            $summary = [
                'version' => 1,
                'status' => Arr::has(['completed', 'parsed', 'confirmation_required', 'invalid', 'failed'], $status, true)
                    ? $status : 'unknown',
                'confirmation_required' => $result->confirmationRequired(),
                'host_failure' => $result->failedAtHost(),
                'phase' => $phase,
            ];
            DevElation::do(ExtensionPointCatalog::COMMAND_RESULT, [$summary]);
        } catch (Throwable) {
            // Observation cannot replace a settled result or replay an operation.
        } finally {
            self::$observingResult = false;
        }
        return $result;
    }
}
