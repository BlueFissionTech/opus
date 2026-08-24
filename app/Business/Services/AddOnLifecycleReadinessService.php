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
        $matching = $reasons->filter(fn ($reason): bool =>
            Arr::getPath((array) $reason, 'stage') === $stage
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

        return $failed->isNotEmpty()
            && Str::make((string) $outcome->get('error'))->trim()->isEmpty()
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

        $stage = (string) ($outcome->get('stage') ?: 'lifecycle');
        $message = (string) $outcome->get('error');
        $matched = false;
        $reasons->each(function ($reason) use ($stage, $message, &$matched): void {
            $matched = $matched
                || Arr::getPath((array) $reason, 'stage') === $stage
                || (
                    Str::isNotEmpty($message)
                    && Arr::getPath((array) $reason, 'message') === $message
                );
        });

        return !$matched
            && (Str::isNotEmpty($message) || !Arr::make(['complete', 'hook'])->contains($stage));
    }

    private function normalizeBatch(Arr $outcome, array $results): array
    {
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
        $total = $normalized->count();
        $failed = $failures->count();

        $outcome->set('ok', $failed === 0);
        $outcome->set('changed', $changes->count() > 0);
        $outcome->set('total', $total);
        $outcome->set('succeeded', $total - $failed);
        $outcome->set('failed', $failed);
        $outcome->set('results', $normalized->toArray());
        if ($failed > 0) {
            $first = Arr::make((array) $reasons->get(0));
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
            'state' => $failed === 0 ? 'ready' : 'blocked',
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

    private function reason(string $code, string $stage, string $message): array
    {
        return [
            'code' => $code,
            'stage' => $stage,
            'message' => $message,
        ];
    }
}
