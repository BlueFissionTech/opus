<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Str;

final class ProfileAccessDecision
{
    private Str $reason;
    private Arr $metadata;

    public function __construct(private bool $allowed, string $reason, array $metadata = [])
    {
        $this->reason = Str::make($reason);
        $this->metadata = Arr::make($metadata);
    }

    public static function allow(string $reason, array $metadata = []): self
    {
        return new self(true, $reason, $metadata);
    }

    public static function deny(string $reason, array $metadata = []): self
    {
        return new self(false, $reason, $metadata);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function reason(): string
    {
        return $this->reason->val();
    }

    public function metadata(): array
    {
        return $this->metadata->toArray();
    }

    public function toArray(): array
    {
        return Arr::merge([
            'decision' => $this->allowed() ? 'allow' : 'deny',
            'reason' => $this->reason(),
        ], $this->metadata());
    }
}
