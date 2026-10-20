<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class BlobReference
{
    public function __construct(
        public string $id,
        public string $sha256,
        public int $bytes
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || $bytes < 0 || $bytes > 65536) {
            throw new InvalidArgumentException('repos_blob_reference_invalid');
        }
        RecordValidation::digest($sha256);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'sha256' => $this->sha256, 'bytes' => $this->bytes];
    }
}
