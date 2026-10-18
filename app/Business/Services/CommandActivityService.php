<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Activity\ActivityEvent;
use App\Domain\Activity\ActivityScope;
use App\Domain\Activity\IActivityStore;
use App\Domain\Console\CommandPresentation;
use BlueFission\Arr;
use BlueFission\Wise\Cmd\CommandRequest;
use InvalidArgumentException;

final class CommandActivityService
{
    public function __construct(private IActivityStore $store, private string $applicationId) {}

    public function begin(CommandRequest $request): ActivityEvent
    {
        $scope = ActivityScope::fromContext($this->applicationId, $request->context());
        $prior = $request->isContinuation()
            ? $this->store->approval($scope, $this->approvalReference((string) $request->continuationToken())) : null;
        if ($prior !== null && (!$prior->scope->matches($scope) || $prior->phase !== 'approval_requested')) {
            $prior = null;
        }
        $phase = $request->isContinuation()
            ? ($request->approved() ? 'approval_submitted' : 'rejection_submitted') : 'requested';
        $operation = $request->isContinuation() ? 'command.resume' : ($request->shouldExecute() ? 'command.execute' : 'command.parse');
        $event = ActivityEvent::create($scope, $phase, $operation, correlationId: $prior?->correlationId, causationId: $prior?->id);
        $this->store->append($event);
        return $event;
    }

    public function finish(ActivityEvent $request, CommandPresentation $result): ActivityEvent
    {
        $pending = $result->confirmationRequired() && $result->continuationToken() !== null;
        $event = ActivityEvent::create(
            $request->scope, $pending ? 'approval_requested' : 'outcome', $request->operation,
            $result->status(), $request->correlationId, $request->id, $result->failedAtHost()
        );
        $this->store->append($event, $pending ? $this->approvalReference((string) $result->continuationToken()) : null);
        return $event;
    }

    private function approvalReference(string $token): string
    {
        // Bearer credentials must not pass through extensible value hooks.
        return hash('sha256', $token);
    }

    public function history(array $context, int $after = 0, int $limit = 100, ?string $correlation = null): array
    {
        if ($after < 0 || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('activity_page_invalid');
        }
        $scope = ActivityScope::fromContext($this->applicationId, $context);
        return Arr::make($this->store->read($scope, $after, $limit, $correlation))
            ->filter(static fn (array $row): bool => $row['event'] instanceof ActivityEvent
                && $row['event']->scope->matches($scope) && $row['sequence'] > $after
                && ($correlation === null || $row['event']->correlationId === $correlation))
            ->slice(0, $limit)
            ->map(static fn (array $row): array => ['sequence' => $row['sequence'], ...$row['event']->toArray()])
            ->values()->toArray();
    }
}
