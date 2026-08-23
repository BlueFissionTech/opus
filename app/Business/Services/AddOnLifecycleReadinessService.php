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

        if (Flag::isFalse($outcome->get('ok')) && $reasons->count() === 0) {
            $reasons->push($this->reason(
                'addon_lifecycle_failed',
                (string) ($outcome->get('stage') ?: 'lifecycle'),
                (string) ($outcome->get('error') ?: 'Add-on lifecycle action failed.')
            ));
        }

        if ($reasons->count() > 0) {
            $first = Arr::make((array) $reasons->get(0));
            $outcome->set('ok', false);
            $outcome->set('stage', $first->get('stage'));
            $outcome->set('nextAction', $outcome->get('nextAction') ?: 'retry_lifecycle');
            $outcome->set('error', $outcome->get('error') ?: $first->get('message'));
        }

        $outcome->set('readiness', [
            'state' => $reasons->count() === 0 ? 'ready' : 'blocked',
            'reasons' => $reasons->toArray(),
        ]);

        return $outcome->toArray();
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
        $outcome->set('readiness', [
            'state' => $failed === 0 ? 'ready' : 'blocked',
            'reasons' => $reasons->toArray(),
        ]);

        return $outcome->toArray();
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
