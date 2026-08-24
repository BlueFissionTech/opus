<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Flag;
use BlueFission\Str;

final class AgentRuntimeResult
{
    public const COMPLETED = 'completed';
    public const DENIED = 'denied';
    public const FAILED = 'failed';
    public const UNAVAILABLE = 'unavailable';

    private Flag $ok;
    private Str $status;
    private Str $action;
    private Str $agentId;
    private ?Str $tenantId;
    private Str $state;
    private mixed $output;
    private Arr $diagnostics;
    private Arr $trace;
    private Arr $metadata;

    public function __construct(
        bool $ok,
        string $status,
        string $action,
        string $agentId,
        ?string $tenantId,
        string $state,
        mixed $output = null,
        array $diagnostics = [],
        array $trace = [],
        array $metadata = []
    ) {
        $this->ok = Flag::make($ok);
        $this->status = Str::make($status);
        $this->action = Str::make($action);
        $this->agentId = Str::make($agentId);
        $this->tenantId = Str::isNotEmpty((string) $tenantId) ? Str::make((string) $tenantId) : null;
        $this->state = Str::make($state);
        $this->output = $output;
        $this->diagnostics = Arr::make($diagnostics);
        $this->trace = Arr::make($trace);
        $this->metadata = Arr::make($metadata);
    }

    public static function completed(
        string $action,
        string $agentId,
        ?string $tenantId,
        string $state,
        mixed $output = null,
        array $metadata = []
    ): self {
        return new self(true, self::COMPLETED, $action, $agentId, $tenantId, $state, $output, [], [], $metadata);
    }

    public static function denied(
        string $action,
        string $agentId,
        ?string $tenantId,
        string $state,
        string $reason
    ): self {
        return new self(false, self::DENIED, $action, $agentId, $tenantId, $state, diagnostics: [$reason]);
    }

    public static function unavailable(
        string $action,
        string $agentId,
        ?string $tenantId,
        string $state,
        string $reason
    ): self {
        return new self(false, self::UNAVAILABLE, $action, $agentId, $tenantId, $state, diagnostics: [$reason]);
    }

    public static function failed(
        string $action,
        string $agentId,
        ?string $tenantId,
        string $state,
        string $reason
    ): self {
        return new self(false, self::FAILED, $action, $agentId, $tenantId, $state, diagnostics: [$reason]);
    }

    public function ok(): bool
    {
        return $this->ok->isTruthy();
    }

    public function status(): string
    {
        return $this->status->val();
    }

    public function state(): string
    {
        return $this->state->val();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics->toArray();
    }

    public function forScope(string $action, string $agentId, ?string $tenantId, string $state): self
    {
        return new self(
            $this->ok(),
            $this->status(),
            $action,
            $agentId,
            $tenantId,
            $state,
            $this->output(),
            $this->diagnostics(),
            $this->trace->toArray(),
            $this->metadata->toArray()
        );
    }

    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->ok(),
            $this->status(),
            $this->action->val(),
            $this->agentId->val(),
            $this->tenantId?->val(),
            $this->state(),
            $this->output(),
            $this->diagnostics(),
            $this->trace->toArray(),
            Arr::merge($this->metadata->toArray(), $metadata)
        );
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'status' => $this->status(),
            'action' => $this->action->val(),
            'agent_id' => $this->agentId->val(),
            'tenant_id' => $this->tenantId?->val(),
            'state' => $this->state(),
            'output' => $this->output(),
            'diagnostics' => $this->diagnostics(),
            'trace' => $this->trace->toArray(),
            'metadata' => $this->metadata->toArray(),
        ];
    }
}
