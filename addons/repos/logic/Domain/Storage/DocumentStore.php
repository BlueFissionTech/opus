<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Storage;

use AddOns\Repos\Domain\Values\Document;
use AddOns\Repos\Domain\Values\StorageResult;

/** Persistence only: callers must authorize the scope and document before access. */
interface DocumentStore
{
    public function write(string $scope, Document $document, string $bytes, string $sha256, int $expectedVersion, string $requestId, int $now, int $retainUntil): StorageResult;
    public function find(string $scope, string $id): ?StorageResult;
    /** @return list<StorageResult> */
    public function documents(string $scope, string $ownerId, string $after = '', int $limit = 25): array;
    public function readBytes(string $scope, string $id, int $version): string;
    public function deleteDocument(string $scope, string $id, int $expectedVersion, int $now): StorageResult;
}
