<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class StorageResult
{
    public function __construct(
        public string $status,
        public ?Document $document = null,
        public ?Version $version = null,
        public bool $replayed = false
    ) {
        if (!in_array($status, ['completed', 'denied', 'conflict', 'unavailable', 'corrupt', 'retained'], true)) {
            throw new InvalidArgumentException('repos_status_invalid');
        }
        if ($status !== 'completed' && ($document !== null || $version !== null || $replayed)) {
            throw new InvalidArgumentException('repos_failed_result_payload');
        }
        if ($version !== null && ($document === null || $version->documentId !== $document->id)) {
            throw new InvalidArgumentException('repos_result_identity_mismatch');
        }
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'document' => $this->document?->toArray(),
            'version' => $this->version?->toArray(), 'replayed' => $this->replayed];
    }
}
