<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Flag;
use BlueFission\Str;

final class AgentDelegationResult
{
    public const COMPLETED = 'completed';
    public const PARTIAL = 'partial';
    public const DENIED = 'denied';
    public const FAILED = 'failed';

    private Flag $ok;
    private Str $status;
    private Str $coordinatorId;
    private Arr $participants;
    private mixed $output;
    private Arr $workerResults;
    private Arr $conflicts;
    private Arr $diagnostics;
    private Arr $metadata;

    public function __construct(
        bool $ok,
        string $status,
        string $coordinatorId,
        array $participants,
        mixed $output = null,
        array $workerResults = [],
        array $conflicts = [],
        array $diagnostics = [],
        array $metadata = []
    ) {
        $this->ok = Flag::make($ok);
        $this->status = Str::make($status);
        $this->coordinatorId = Str::make($coordinatorId);
        $this->participants = Arr::make($participants)->unique();
        $this->output = $output;
        $this->workerResults = Arr::make($workerResults);
        $this->conflicts = Arr::make($conflicts);
        $this->diagnostics = Arr::make($diagnostics);
        $this->metadata = Arr::make($metadata);
    }

    public static function denied(string $coordinatorId, array $participants, string $reason): self
    {
        return new self(false, self::DENIED, $coordinatorId, $participants, diagnostics: [$reason]);
    }

    public static function failed(string $coordinatorId, array $participants, string $reason): self
    {
        return new self(false, self::FAILED, $coordinatorId, $participants, diagnostics: [$reason]);
    }

    public function ok(): bool
    {
        return $this->ok->isTruthy();
    }

    public function status(): string
    {
        return $this->status->val();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function diagnostics(): array
    {
        return $this->diagnostics->toArray();
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'status' => $this->status(),
            'coordinator_id' => $this->coordinatorId->val(),
            'participants' => $this->participants->toArray(),
            'output' => $this->output(),
            'worker_results' => $this->workerResults->toArray(),
            'conflicts' => $this->conflicts->toArray(),
            'diagnostics' => $this->diagnostics(),
            'metadata' => $this->metadata->toArray(),
        ];
    }
}
