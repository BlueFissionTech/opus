<?php
declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Domain\Activity\ActivityEvent;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Services\Service;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Throwable;

final class CommandHost extends Service
{
    private ?CommandActivityService $activity;
    private static bool $observingResult = false;

    public function __construct(
        private ICommandProcessor $processor,
        private CommandResultPresenter $presenter,
        ?object $activity = null
    ) {
        parent::__construct();
        // The container eagerly resolves optional class types; this is opt-in.
        if ($activity !== null && !$activity instanceof CommandActivityService) {
            throw new \InvalidArgumentException('command_activity_invalid');
        }
        $this->activity = $activity;
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
        $receipt = null;
        try {
            $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
            $receipt = $this->activity?->begin($request);
        } catch (Throwable) {
            return $this->observe(CommandPresentation::hostFailure(
                $this->activity === null ? 'processing' : 'activity_start'), 'processing_failed');
        }
        try {
            $result = $this->processor->process($request);
        } catch (Throwable) {
            return $this->observe($this->record($receipt, CommandPresentation::hostFailure('processing')), 'processing_failed');
        }
        try {
            $presentation = $this->presenter->present($result);
        } catch (Throwable) {
            return $this->observe($this->record($receipt, CommandPresentation::hostFailure('presentation')), 'presentation_failed');
        }
        return $this->observe($this->record($receipt, $presentation), 'presented');
    }

    public function history(array $context, int $after = 0, int $limit = 100, ?string $correlation = null): array
    {
        if ($this->activity === null) {
            throw new \LogicException('command_activity_not_configured');
        }
        return $this->activity->history($context, $after, $limit, $correlation);
    }

    private function record(?ActivityEvent $receipt, CommandPresentation $result): CommandPresentation
    {
        try {
            if ($receipt !== null) {
                $recorded = $this->activity?->finish($receipt, $result);
                if ($recorded !== null) { return $result->withActivityReceipt($recorded); }
            }
            return $result;
        } catch (Throwable) {
            $failure = CommandPresentation::hostFailure('activity_recording');
            return $receipt === null ? $failure : $failure->withActivityReceipt($receipt);
        }
    }

    public function execute(Command|array|string $input, array $context = []): CommandPresentation
    {
        return $this->process(new CommandRequest($input, context: $context));
    }

    public function resume(string $continuationToken, bool $approved, array $context = []): CommandPresentation
    {
        return $this->process(CommandRequest::resume($continuationToken, $approved, $context));
    }

    private function observe(CommandPresentation $result, string $phase): CommandPresentation
    {
        self::$observingResult = true;
        try {
            $status = $result->status();
            DevElation::do(ExtensionPointCatalog::COMMAND_RESULT, [[
                'version' => 1,
                'status' => Arr::has(['completed', 'parsed', 'confirmation_required', 'invalid', 'failed'], $status, true)
                    ? $status : 'unknown',
                'confirmation_required' => $result->confirmationRequired(),
                'host_failure' => $result->failedAtHost(),
                'phase' => $phase,
            ]]);
        } catch (Throwable) {
            // Observation cannot replace a settled result or replay an operation.
        } finally { self::$observingResult = false; }
        return $result;
    }
}
