<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class Version
{
    public function __construct(
        public string $documentId,
        public int $number,
        public BlobReference $blob,
        public int $createdAt,
        public int $retainUntil
    ) {
        RecordValidation::identifier($documentId);
        if ($number < 1 || $createdAt < 0 || $retainUntil < $createdAt) {
            throw new InvalidArgumentException('repos_version_invalid');
        }
    }

    public function toArray(): array
    {
        return ['document_id' => $this->documentId, 'number' => $this->number,
            'blob' => $this->blob->toArray(), 'created_at' => $this->createdAt,
            'retain_until' => $this->retainUntil];
    }
}
