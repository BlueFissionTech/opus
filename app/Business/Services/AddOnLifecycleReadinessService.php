<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\Flag;
use BlueFission\Services\Service;
use BlueFission\Str;

final class AddOnLifecycleReadinessService extends Service
{
    private const REQUIRED_STAGES = [
        'migrations' => 'addon_migration_failed',
        'population' => 'addon_population_failed',
    ];

    public function normalize(array $outcome): array
    {
        $outcome = Arr::make($outcome);
        $results = $outcome->get('results');
        if (Arr::is($results)) {
            return $this->normalizeBatch($outcome, (array) $results);
        }

        return $this->normalizeLifecycle($outcome);
    }

    public function failure(string $action, string $code, string $message): array
    {
        return [
            'ok' => false,
            'action' => $action,
            'changed' => false,
            'stage' => 'request',
            'nextAction' => 'correct_request',
            'error' => $message,
            'messages' => [$message],
            'hooks' => [],
            'readiness' => [
                'state' => 'blocked',
                'reasons' => [$this->reason($code, 'request', $message)],
            ],
        ];
    }

    public function listing(array $addOns): array
    {
        return [
            'ok' => true,
            'action' => 'show_all',
            'changed' => false,
            'total' => Arr::size($addOns),
            'results' => Arr::make($addOns)->values()->toArray(),
            'readiness' => [
                'state' => 'ready',
                'reasons' => [],
            ],
        ];
    }

    private function normalizeLifecycle(Arr $outcome): array
    {
        $reasons = Arr::make([]);
        $optionalHookFailureOnly = $this->aggregateFailureIsOptionalHookOnly($outcome);
        $hooks = Arr::make(Arr::is($outcome->get('hooks')) ? $outcome->get('hooks') : [])
            ->map(fn ($hook): array => $this->normalizeHook(Arr::make((array) $hook), $reasons))
            ->values();
        $outcome->set('hooks', $hooks->toArray());

        Arr::make(self::REQUIRED_STAGES)->each(function (string $code, string $stage) use ($outcome, $reasons): void {
            $result = $outcome->get($stage);
            if (!Arr::is($result) || !Arr::hasKey($result, 'ok') || !Flag::isFalse(Arr::getPath($result, 'ok'))) {
                return;
            }

            $message = (string) Arr::getPath($result, 'error', "Add-on {$stage} failed.");
            $reasons->push($this->reason($code, $stage, $message));
        });

        if ($this->hasIndependentAggregateFailure($outcome, $reasons, $optionalHookFailureOnly)) {
            $reasons->unshift($this->reason(
                'addon_lifecycle_failed',
                (string) ($outcome->get('stage') ?: 'lifecycle'),
                (string) ($outcome->get('error') ?: 'Add-on lifecycle action failed.')
            ));
        }

        if ($reasons->count() > 0) {
            $first = $this->selectedReason($outcome, $reasons);
            $outcome->set('ok', false);
            $outcome->set('stage', $first->get('stage'));
            $outcome->set('nextAction', 'retry_lifecycle');
            $outcome->set('error', $first->get('message'));
        } elseif ($optionalHookFailureOnly) {
            $outcome->set('ok', true);
            $outcome->set('stage', 'complete');
            $outcome->set('error', null);
            if ((string) $outcome->get('nextAction') === 'retry_lifecycle') {
                $outcome->set('nextAction', $this->successfulNextAction((string) $outcome->get('action')));
            }
        }

        $outcome->set('readiness', [
            'state' => $reasons->count() === 0 ? 'ready' : 'blocked',
            'reasons' => $reasons->toArray(),
        ]);

        return $outcome->toArray();
    }

    private function selectedReason(Arr $outcome, Arr $reasons): Arr
    {
        $stage = (string) $outcome->get('stage');
        $message = (string) $outcome->get('error');
        $messageSelectsStage = $this->messageSelectsStage($stage)
            && Str::isNotEmpty($message);
        $matching = $reasons->filter(fn ($reason): bool =>
            (
                Str::isEmpty($stage)
                || Arr::getPath((array) $reason, 'stage') === $stage
                || ($messageSelectsStage
                    && Arr::getPath((array) $reason, 'message') === $message)
            )
            && (
                Str::isEmpty($message)
                || Arr::getPath((array) $reason, 'message') === $message
            )
        )->values();

        return Arr::make((array) ($matching->get(0) ?? $reasons->get(0)));
    }

    private function normalizeHook(Arr $hook, Arr $reasons): array
    {
        $status = Str::make((string) $hook->get('status'))->trim()->lower()->val();
        if ($status === 'missing_callable') {
            $hook->set('ok', true);
            $hook->set('status', 'skipped');
            $hook->set('optional', true);
            $hook->set('reason', 'lifecycle_hook_not_declared');
            $hook->set('error', null);

            return $hook->toArray();
        }

        if (Flag::isFalse($hook->get('ok'))) {
            $reasons->push($this->reason(
                'addon_hook_failed',
                'hook',
                (string) ($hook->get('error') ?: 'Add-on lifecycle hook failed.')
            ));
        }

        return $hook->toArray();
    }

    private function aggregateFailureIsOptionalHookOnly(Arr $outcome): bool
    {
        $failed = Arr::make(Arr::is($outcome->get('hooks')) ? $outcome->get('hooks') : [])
            ->filter(fn ($hook): bool => Flag::isFalse(Arr::getPath((array) $hook, 'ok')))
            ->values();
        $stage = Str::make((string) $outcome->get('stage'))->trim()->lower()->val();
        $error = Str::make((string) $outcome->get('error'))->trim()->val();
        $failedMessages = $failed
            ->map(fn ($hook): string => Str::make(
                (string) Arr::getPath((array) $hook, 'error')
            )->trim()->val())
            ->filter(fn (string $message): bool => Str::isNotEmpty($message))
            ->unique()
            ->values();
        $aggregateErrorIsOptional = Str::isEmpty($error)
            || ($failedMessages->count() === 1 && $failedMessages->get(0) === $error);

        return $failed->isNotEmpty()
            && $aggregateErrorIsOptional
            && Arr::make(['', 'complete', 'hook'])->contains($stage)
            && $failed->filter(fn ($hook): bool => Str::make(
                (string) Arr::getPath((array) $hook, 'status')
            )->trim()->lower()->val() !== 'missing_callable')->isEmpty();
    }

    private function hasIndependentAggregateFailure(Arr $outcome, Arr $reasons, bool $optionalHookFailureOnly): bool
    {
        if (!Flag::isFalse($outcome->get('ok')) || $optionalHookFailureOnly) {
            return false;
        }
        if ($reasons->isEmpty()) {
            return true;
        }

        $stage = (string) $outcome->get('stage');
        $message = (string) $outcome->get('error');
        if (Str::isEmpty($stage) && Str::isEmpty($message)) {
            return false;
        }

        if ($this->messageSelectsStage($stage)
            && $this->hasMatchingReasonMessage($reasons, $message)
        ) {
            return false;
        }

        $stage = $stage ?: 'lifecycle';
        $matched = $this->hasMatchingReason($reasons, $stage, $message);

        return !$matched
            && (Str::isNotEmpty($message) || !Arr::make(['complete', 'hook'])->contains($stage));
    }

    private function normalizeBatch(Arr $outcome, array $results): array
    {
        $aggregateFailed = Flag::isFalse($outcome->get('ok'));
        $aggregateStage = (string) $outcome->get('stage');
        $aggregateError = (string) $outcome->get('error');
        $normalized = Arr::make($results)
            ->map(fn ($result): array => $this->normalizeLifecycle(Arr::make((array) $result)))
            ->values();
        $failures = $normalized
            ->filter(fn (array $result): bool => Flag::isFalse(Arr::getPath($result, 'ok', false)))
            ->values();
        $changes = $normalized
            ->filter(fn (array $result): bool => Flag::parseBool(Arr::getPath($result, 'changed', false)))
            ->values();
        $reasons = Arr::make([]);
        $failures->each(function (array $result) use ($reasons): void {
            $reasons->mergeRecursive((array) Arr::getPath($result, 'readiness.reasons', []));
        });
        $recoveredChildFailuresOnly = $this->batchFailureIsRecoveredChildrenOnly(
            $results,
            $aggregateStage
        );
        $aggregateExplainedByChildren = $reasons->isNotEmpty()
            && Str::isEmpty($aggregateError)
            && Arr::make(['', 'complete'])->contains(
                Str::make($aggregateStage)->trim()->lower()->val()
            );
        $aggregateMatchesChild = $this->hasMatchingReason($reasons, $aggregateStage, $aggregateError)
            || ($this->messageSelectsStage($aggregateStage)
                && $this->hasMatchingReasonMessage($reasons, $aggregateError));
        $independentAggregateFailure = $aggregateFailed
            && !$recoveredChildFailuresOnly
            && !$aggregateExplainedByChildren
            && !$aggregateMatchesChild;
        if ($independentAggregateFailure) {
            $reasons->unshift($this->reason(
                'addon_lifecycle_failed',
                $aggregateStage ?: 'lifecycle',
                $aggregateError ?: 'Add-on lifecycle batch failed.'
            ));
        }
        $total = $normalized->count();
        $failed = $failures->count();
        $blocked = $failed > 0 || $independentAggregateFailure;

        $outcome->set('ok', !$blocked);
        $outcome->set('changed', $changes->count() > 0);
        $outcome->set('total', $total);
        $outcome->set('succeeded', $total - $failed);
        $outcome->set('failed', $failed);
        $outcome->set('results', $normalized->toArray());
        if ($blocked) {
            $first = $this->selectedReason($outcome, $reasons);
            $outcome->set('stage', $first->get('stage') ?: 'lifecycle');
            $outcome->set('error', $first->get('message') ?: 'Add-on lifecycle action failed.');
            $outcome->set('nextAction', 'retry_lifecycle');
        } else {
            $outcome->set('stage', 'complete');
            $outcome->set('error', null);
            if ((string) $outcome->get('nextAction') === 'retry_lifecycle') {
                $outcome->set('nextAction', $this->successfulNextAction((string) $outcome->get('action')));
            }
        }
        $outcome->set('readiness', [
            'state' => $blocked ? 'blocked' : 'ready',
            'reasons' => $reasons->toArray(),
        ]);

        return $outcome->toArray();
    }

    private function successfulNextAction(string $action): ?string
    {
        $action = Str::make($action)->trim()->lower()->val();
        if ($action === 'install') {
            return 'activate';
        }

        return $action === 'install_all' ? 'activate_all' : null;
    }

    private function hasMatchingReason(Arr $reasons, string $stage, string $message): bool
    {
        $matched = false;
        $reasons->each(function ($reason) use ($stage, $message, &$matched): void {
            $matched = $matched || (
                Arr::getPath((array) $reason, 'stage') === $stage
                && (
                    Str::isEmpty($message)
                    || Arr::getPath((array) $reason, 'message') === $message
                )
            );
        });

        return $matched;
    }

    private function hasMatchingReasonMessage(Arr $reasons, string $message): bool
    {
        if (Str::isEmpty($message)) {
            return false;
        }

        $matched = false;
        $reasons->each(function ($reason) use ($message, &$matched): void {
            $matched = $matched || Arr::getPath((array) $reason, 'message') === $message;
        });

        return $matched;
    }

    private function messageSelectsStage(string $stage): bool
    {
        return Arr::make(['', 'complete'])->contains(
            Str::make($stage)->trim()->lower()->val()
        );
    }

    private function batchFailureIsRecoveredChildrenOnly(array $results, string $aggregateStage): bool
    {
        if (!Arr::make(['', 'complete', 'hook'])->contains($aggregateStage)) {
            return false;
        }
        $failed = Arr::make($results)
            ->filter(fn ($result): bool => Flag::isFalse(Arr::getPath((array) $result, 'ok')))
            ->values();

        return $failed->isNotEmpty()
            && $failed->filter(
                fn ($result): bool => !$this->aggregateFailureIsOptionalHookOnly(Arr::make((array) $result))
            )->isEmpty();
    }

    private function reason(string $code, string $stage, string $message): array
    {
        return [
            'code' => $code,
            'stage' => $stage,
            'message' => $message,
        ];
    }
}
