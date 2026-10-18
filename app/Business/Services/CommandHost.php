<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Business\Presentation\CommandResultPresenter;
use App\Domain\Activity\ActivityEvent;
use App\Domain\Console\CommandPresentation;
use BlueFission\Services\Service;
use BlueFission\Wise\Cmd\Command;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\ICommandProcessor;
use Throwable;

final class CommandHost extends Service
{
    private ?CommandActivityService $activity;

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
        $receipt = null;
        try {
            $request = $request instanceof CommandRequest ? $request : new CommandRequest($request);
            $receipt = $this->activity?->begin($request);
        } catch (Throwable) {
            return CommandPresentation::hostFailure($this->activity === null ? 'processing' : 'activity_start');
        }
        try {
            $result = $this->processor->process($request);
        } catch (Throwable) {
            return $this->record($receipt, CommandPresentation::hostFailure('processing'));
        }
        try {
            $presentation = $this->presenter->present($result);
        } catch (Throwable) {
            $presentation = CommandPresentation::hostFailure('presentation');
        }
        return $this->record($receipt, $presentation);
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
                if ($recorded !== null) {
                    return $result->withActivityReceipt($recorded);
                }
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

    public function resume(
        string $continuationToken,
        bool $approved,
        array $context = []
    ): CommandPresentation {
        return $this->process(CommandRequest::resume($continuationToken, $approved, $context));
    }
}
