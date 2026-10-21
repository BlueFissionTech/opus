<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use BlueFission\Arr;
use BlueFission\Date;
use BlueFission\Flag;
use BlueFission\Obj;
use BlueFission\Str;
use InvalidArgumentException;

/** Closed receipt schema: no provider payloads or arbitrary extension metadata. */
final class ActivityEvent extends Obj
{
    protected $_immutable = true;

    private const PHASES = ['requested', 'approval_requested', 'approval_submitted', 'rejection_submitted', 'outcome'];
    private const STATUSES = ['requested', 'confirmation_required', 'completed', 'parsed', 'invalid', 'failed', 'unknown'];

    private function __construct(
        public readonly ActivityScope $scope,
        public readonly string $id,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly string $phase,
        public readonly string $operation,
        public readonly string $status,
        public readonly string $timestamp,
        public readonly bool $hostFailure
    ) {
        foreach ([$id, $correlationId, ...($causationId === null ? [] : [$causationId])] as $reference) {
            if (!Str::make($reference)->matches('/^[a-f0-9]{32}$/D')) {
                throw new InvalidArgumentException('activity_reference_invalid');
            }
        }
        if (!Arr::has(self::PHASES, $phase, true) || !Arr::has(self::STATUSES, $status, true)
            || !Arr::has(['command.execute', 'command.parse', 'command.resume'], $operation, true)
            || !Str::make($timestamp)->matches('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D')
        ) {
            throw new InvalidArgumentException('activity_event_invalid');
        }
        $this->_data = $this->toArray();
        parent::__construct();
    }

    public function serialize(): string
    {
        return $this->toJson();
    }

    public function __serialize(): array
    {
        return $this->toArray();
    }

    public function __unserialize(array $data): void
    {
        $event = self::fromArray($data);
        $this->__construct($event->scope, $event->id, $event->correlationId, $event->causationId,
            $event->phase, $event->operation, $event->status, $event->timestamp, $event->hostFailure);
    }

    public static function create(
        ActivityScope $scope,
        string $phase,
        string $operation,
        string $status = 'requested',
        ?string $correlationId = null,
        ?string $causationId = null,
        bool $hostFailure = false
    ): self {
        $id = bin2hex(random_bytes(16));
        return new self($scope, $id, $correlationId ?? $id, $causationId, $phase, $operation,
            Arr::has(self::STATUSES, $status, true) ? $status : 'unknown',
            Date::now()->format('c')->val(), $hostFailure);
    }

    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== 1 || !Flag::isBool($data['host_failure'] ?? null)) {
            throw new InvalidArgumentException('activity_version_invalid');
        }
        foreach (['application_id', 'actor_id', 'event_id', 'correlation_id', 'phase', 'operation', 'status', 'occurred_at'] as $field) {
            if (!Str::is($data[$field] ?? null)) {
                throw new InvalidArgumentException('activity_event_invalid');
            }
        }
        foreach (['tenant_id', 'causation_id'] as $field) {
            if (!Arr::hasKey($data, $field) || ($data[$field] !== null && !Str::is($data[$field]))) {
                throw new InvalidArgumentException('activity_event_invalid');
            }
        }
        return new self(
            new ActivityScope($data['application_id'], $data['tenant_id'], $data['actor_id']),
            $data['event_id'], $data['correlation_id'], $data['causation_id'], $data['phase'],
            $data['operation'], $data['status'], $data['occurred_at'], $data['host_failure']
        );
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'event_id' => $this->id,
            'application_id' => $this->scope->applicationId,
            'tenant_id' => $this->scope->tenantId,
            'actor_id' => $this->scope->actorId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'operation' => $this->operation,
            'phase' => $this->phase,
            'status' => $this->status,
            'occurred_at' => $this->timestamp,
            'mechanism' => 'unknown',
            'effects' => 'unknown',
            'host_failure' => $this->hostFailure,
            'reason_codes' => $this->hostFailure ? ['command_host_failure'] : [],
            'capability' => null,
            'policy_refs' => [],
            'grant_refs' => [],
            'evidence_refs' => [],
            'artifact_refs' => [],
            'provider_identity' => null,
            'source_freshness' => 'unknown',
        ];
    }
}
