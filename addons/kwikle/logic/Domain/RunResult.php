<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

final readonly class RunResult
{
    public function __construct(public string $status, public string $effects, public int $attempt,
        public ?string $correlation) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'effects' => $this->effects,
            'attempt' => $this->attempt, 'correlation_id' => $this->correlation];
    }
}
