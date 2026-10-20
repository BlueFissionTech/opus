<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class Share
{
    public function __construct(
        public string $documentId,
        public string $recipientId,
        public int $expiresAt
    ) {
        RecordValidation::identifier($documentId);
        RecordValidation::identifier($recipientId);
        if ($expiresAt < 1) { throw new InvalidArgumentException('repos_share_expiry_invalid'); }
    }

    public function permitsReadAt(int $now): bool
    {
        return $now >= 0 && $now < $this->expiresAt;
    }

    public function toArray(): array
    {
        return ['document_id' => $this->documentId, 'recipient_id' => $this->recipientId,
            'expires_at' => $this->expiresAt, 'permission' => 'read'];
    }
}
