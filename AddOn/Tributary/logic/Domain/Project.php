<?php

declare(strict_types=1);

namespace AddOn\Tributary\Domain;

use BlueFission\Arr;
use BlueFission\Str;
use InvalidArgumentException;

/** Reviewed configuration, not an authentication or executable-code document. */
final readonly class Project
{
    public array $profiles;
    public array $roles;
    public array $members;
    public array $tasks;

    public function __construct(
        public string $id,
        public string $applicationId,
        public string $tenantId,
        array $profiles,
        array $roles,
        array $members,
        array $tasks
    ) {
        foreach ([$id, $applicationId, $tenantId] as $value) { self::identifier($value); }
        [$this->profiles, $this->roles, $this->members, $this->tasks] = self::snapshot([$profiles, $roles, $members, $tasks]);
        foreach ($profiles as $key => $profile) {
            self::identifier($key);
            if (!Arr::is($profile)) { throw new InvalidArgumentException('Invalid profile.'); }
            self::identifier(Arr::getPath($profile, 'agent_id'));
            self::identifier(Arr::getPath($profile, 'profile'));
        }
        foreach ($roles as $key => $grants) { self::identifier($key); self::identifiers($grants); }
        foreach ($members as $actor => $assignedRoles) {
            self::identifier($actor);
            foreach (self::identifiers($assignedRoles) as $role) {
                if (!Arr::hasKey($roles, $role)) { throw new InvalidArgumentException('Unknown role.'); }
            }
        }
        foreach ($tasks as $key => $task) {
            self::identifier($key);
            if (!Arr::is($task)) { throw new InvalidArgumentException('Invalid task.'); }
            $source = Arr::getPath($task, 'source');
            self::identifier($source);
            $targets = self::identifiers(Arr::getPath($task, 'targets'));
            if ($targets === [] || !Arr::hasKey($profiles, $source)) {
                throw new InvalidArgumentException('Invalid assignment.');
            }
            foreach ($targets as $target) {
                if (!Arr::hasKey($profiles, $target) || $target === $source) {
                    throw new InvalidArgumentException('Unknown or recursive target.');
                }
            }
            foreach (self::identifiers(Arr::getPath($task, 'capabilities')) as $grant) {
                if (Str::startsWith($grant, 'tributary.') || Str::startsWith($grant, 'opus.runtime.')) {
                    throw new InvalidArgumentException('Administrative grants cannot be delegated.');
                }
            }
            if (!Arr::is(Arr::getPath($task, 'input'))) { throw new InvalidArgumentException('Invalid task input.'); }
        }
    }

    public function toArray(): array
    {
        return ['version' => 1, 'id' => $this->id, 'application_id' => $this->applicationId,
            'tenant_id' => $this->tenantId, 'profiles' => $this->profiles, 'roles' => $this->roles,
            'members' => $this->members, 'tasks' => $this->tasks];
    }

    public static function identifier(mixed $value): void
    {
        if (!Str::is($value) || Str::isEmpty(Str::trim($value)) || Str::len($value) > 256) {
            throw new InvalidArgumentException('Expected a bounded identifier.');
        }
    }

    public static function identifiers(mixed $values): array
    {
        // Exact PHP list shape is part of the portable record schema.
        if (!is_array($values) || !array_is_list($values)) { throw new InvalidArgumentException('Expected an identifier list.'); }
        foreach ($values as $value) { self::identifier($value); }
        return Arr::make($values)->unique()->values()->toArray();
    }

    public static function data(mixed $value, int $depth = 0): void
    {
        if ($depth > 16) { throw new InvalidArgumentException('Record nesting exceeds the limit.'); }
        if (is_array($value)) {
            foreach ($value as $item) { self::data($item, $depth + 1); }
        } elseif ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Records must contain only data.');
        } elseif (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Record numbers must be finite.');
        }
    }

    public static function snapshot(array $value): array
    {
        self::data($value);
        // The JSON record boundary also severs nested PHP references and rejects invalid UTF-8.
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
