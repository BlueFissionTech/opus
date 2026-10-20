<?php

declare(strict_types=1);

namespace AddOns\Repos\Infrastructure;

use AddOns\Repos\Domain\Storage\DocumentStore;
use AddOns\Repos\Domain\Values\{BlobReference, Document, RecordValidation, StorageResult, Version};
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Services\Service;
use RuntimeException;
use SQLite3;
use Throwable;

/**
 * Small documents only. SQLiteLink owns connection lifetime; this adapter owns
 * the prepared statements and transaction spanning metadata, bytes and receipt.
 */
class SQLiteDocumentStore extends Service implements DocumentStore
{
    public function __construct(private SQLiteLink $link)
    {
        parent::__construct();
    }

    /** Explicit installation step; constructing or discovering the package does not migrate. */
    public function install(): void
    {
        $this->transaction(function (): void {
            $this->query('CREATE TABLE IF NOT EXISTS repos_documents (
                scope TEXT NOT NULL, id TEXT NOT NULL, name TEXT NOT NULL, owner_id TEXT NOT NULL,
                media_type TEXT NOT NULL, revision INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY(scope, id))');
            $this->query('CREATE TABLE IF NOT EXISTS repos_versions (
                scope TEXT NOT NULL, document_id TEXT NOT NULL, version INTEGER NOT NULL,
                blob_id TEXT NOT NULL, sha256 TEXT NOT NULL, byte_count INTEGER NOT NULL, bytes BLOB NOT NULL,
                created_at INTEGER NOT NULL, retain_until INTEGER NOT NULL,
                PRIMARY KEY(scope, document_id, version))');
            $this->query('CREATE TABLE IF NOT EXISTS repos_receipts (
                scope TEXT NOT NULL, request_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
                document_id TEXT NOT NULL, version INTEGER NOT NULL,
                PRIMARY KEY(scope, request_id))');
        });
    }

    /** Refuse destructive rollback, including retained deletion tombstones. */
    public function uninstallEmpty(): void
    {
        $this->transaction(function (): void {
            foreach (['repos_documents', 'repos_versions', 'repos_receipts'] as $table) {
                if ($this->query('SELECT 1 FROM ' . $table . ' LIMIT 1') !== []) {
                    throw new RuntimeException('repos_store_not_empty');
                }
            }
            foreach (['repos_receipts', 'repos_versions', 'repos_documents'] as $table) {
                $this->query('DROP TABLE ' . $table);
            }
        });
    }

    public function write(string $scope, Document $document, string $bytes, string $sha256, int $expectedVersion, string $requestId, int $now, int $retainUntil): StorageResult
    {
        RecordValidation::identifier($requestId);
        RecordValidation::digest($sha256);
        if ($document->folderId !== null) {
            throw new RuntimeException('repos_folders_unavailable');
        }
        if ($scope === '' || strlen($scope) > 512 || strlen($bytes) > 65536
            || $expectedVersion < 0 || $now < 0 || $retainUntil < $now) {
            throw new RuntimeException('repos_write_invalid');
        }
        // Exact bytes and retry identity must not be rewritten by global value filters.
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            return new StorageResult('corrupt');
        }
        $fingerprint = hash('sha256', json_encode(
            [$document->toArray(), $sha256, strlen($bytes), $expectedVersion, $now, $retainUntil],
            JSON_THROW_ON_ERROR
        ));
        try {
            return $this->transaction(function () use ($scope, $document, $bytes, $sha256, $expectedVersion, $requestId, $now, $retainUntil, $fingerprint): StorageResult {
                $receipts = $this->query('SELECT * FROM repos_receipts WHERE scope = :scope AND request_id = :request',
                    [':scope' => $scope, ':request' => $requestId]);
                if ($receipts !== []) {
                    $receipt = $receipts[0];
                    if (!hash_equals($receipt['fingerprint'], $fingerprint)) {
                        return new StorageResult('conflict');
                    }
                    return $this->version($scope, $document->id, (int) $receipt['version'], true)
                        ?? new StorageResult('conflict');
                }
                $rows = $this->query('SELECT * FROM repos_documents WHERE scope = :scope AND id = :id',
                    [':scope' => $scope, ':id' => $document->id]);
                if ($rows === []) {
                    if ($expectedVersion !== 0) {
                        return new StorageResult('conflict');
                    }
                    $this->query('INSERT INTO repos_documents (scope,id,name,owner_id,media_type,revision)
                        VALUES (:scope,:id,:name,:owner,:type,1)', [
                            ':scope' => $scope, ':id' => $document->id, ':name' => $document->name,
                            ':owner' => $document->ownerId, ':type' => $document->mediaType,
                        ]);
                } else {
                    $current = $rows[0];
                    if ((int) $current['deleted'] !== 0 || (int) $current['revision'] !== $expectedVersion
                        || $current['owner_id'] !== $document->ownerId || $current['name'] !== $document->name
                        || $current['media_type'] !== $document->mediaType) {
                        return new StorageResult('conflict');
                    }
                    $this->query('UPDATE repos_documents SET revision = :revision WHERE scope = :scope AND id = :id',
                        [':revision' => $expectedVersion + 1, ':scope' => $scope, ':id' => $document->id]);
                }
                $this->query('INSERT INTO repos_versions (scope,document_id,version,blob_id,sha256,byte_count,bytes,created_at,retain_until)
                    VALUES (:scope,:id,:version,:blob,:sha,:size,:bytes,:created,:retained)', [
                        ':scope' => $scope, ':id' => $document->id, ':version' => $expectedVersion + 1,
                        ':blob' => bin2hex(random_bytes(16)), ':sha' => $sha256, ':size' => strlen($bytes), ':bytes' => $bytes,
                        ':created' => $now, ':retained' => $retainUntil,
                    ]);
                $this->query('INSERT INTO repos_receipts (scope,request_id,fingerprint,document_id,version)
                    VALUES (:scope,:request,:fingerprint,:id,:version)', [
                        ':scope' => $scope, ':request' => $requestId, ':fingerprint' => $fingerprint,
                        ':id' => $document->id, ':version' => $expectedVersion + 1,
                    ]);
                return $this->version($scope, $document->id, $expectedVersion + 1)
                    ?? throw new RuntimeException('repos_storage_unavailable');
            });
        } catch (Throwable) {
            return new StorageResult('unavailable');
        }
    }

    public function find(string $scope, string $id): ?StorageResult
    {
        return $this->version($scope, $id);
    }

    public function documents(string $scope, string $ownerId, string $after = '', int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('repos_page_invalid');
        }
        $rows = $this->query('SELECT id FROM repos_documents
            WHERE scope = :scope AND owner_id = :owner AND deleted = 0 AND id > :after ORDER BY id LIMIT :limit',
            [':scope' => $scope, ':owner' => $ownerId, ':after' => $after, ':limit' => $limit]);
        $documents = [];
        foreach ($rows as $row) {
            $record = $this->find($scope, $row['id']);
            if ($record !== null) {
                $documents[] = $record;
            }
        }
        return $documents;
    }

    /** Binary output is restricted to the authorized transfer surface, never an observer. */
    public function readBytes(string $scope, string $id, int $version): string
    {
        $rows = $this->query('SELECT v.bytes, v.sha256, v.byte_count FROM repos_versions v
            JOIN repos_documents d ON d.scope = v.scope AND d.id = v.document_id
            WHERE v.scope = :scope AND v.document_id = :id AND v.version = :version AND d.deleted = 0',
            [':scope' => $scope, ':id' => $id, ':version' => $version]);
        if ($rows === []) {
            throw new RuntimeException('repos_document_unavailable');
        }
        $row = $rows[0];
        if (strlen($row['bytes']) > 65536 || strlen($row['bytes']) !== (int) $row['byte_count']
            || !hash_equals($row['sha256'], hash('sha256', $row['bytes']))) {
            throw new RuntimeException('repos_document_corrupt');
        }
        return $row['bytes'];
    }

    public function deleteDocument(string $scope, string $id, int $expectedVersion, int $now): StorageResult
    {
        if ($now < 0 || $expectedVersion < 1) {
            throw new RuntimeException('repos_delete_invalid');
        }
        try {
            return $this->transaction(function () use ($scope, $id, $expectedVersion, $now): StorageResult {
                $current = $this->find($scope, $id);
                if ($current === null || $current->version->number !== $expectedVersion) {
                    return new StorageResult('conflict');
                }
                if ($this->query('SELECT 1 FROM repos_versions WHERE scope = :scope AND document_id = :id AND retain_until > :now LIMIT 1',
                    [':scope' => $scope, ':id' => $id, ':now' => $now]) !== []) {
                    return new StorageResult('retained');
                }
                $this->query('DELETE FROM repos_versions WHERE scope = :scope AND document_id = :id',
                    [':scope' => $scope, ':id' => $id]);
                $this->query('UPDATE repos_documents SET deleted = 1 WHERE scope = :scope AND id = :id',
                    [':scope' => $scope, ':id' => $id]);
                return new StorageResult('completed');
            });
        } catch (Throwable) {
            return new StorageResult('unavailable');
        }
    }

    private function version(string $scope, string $id, ?int $number = null, bool $replayed = false): ?StorageResult
    {
        $rows = $this->query('SELECT d.*, v.version, v.blob_id, v.sha256, v.byte_count, v.created_at, v.retain_until
            FROM repos_documents d JOIN repos_versions v ON v.scope = d.scope AND v.document_id = d.id
            WHERE d.scope = :scope AND d.id = :id AND d.deleted = 0 AND v.version = ' . ($number === null ? 'd.revision' : ':version'),
            $number === null ? [':scope' => $scope, ':id' => $id] : [':scope' => $scope, ':id' => $id, ':version' => $number]);
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        return new StorageResult('completed',
            new Document($row['id'], $row['name'], $row['owner_id'], null, $row['media_type']),
            new Version($row['id'], (int) $row['version'],
                new BlobReference($row['blob_id'], $row['sha256'], (int) $row['byte_count']),
                (int) $row['created_at'], (int) $row['retain_until']), $replayed);
    }

    private function transaction(callable $operation): mixed
    {
        $database = $this->database();
        $started = false;
        try {
            $this->query('BEGIN IMMEDIATE');
            $started = true;
            $result = $operation();
            $this->query('COMMIT');
            return $result;
        } catch (Throwable $error) {
            if ($started) {
                @$database->exec('ROLLBACK');
            }
            throw $error;
        }
    }

    private function database(): SQLite3
    {
        $database = $this->link->connection();
        if (!$database instanceof SQLite3) {
            throw new RuntimeException('repos_storage_unavailable');
        }
        return $database;
    }

    /** Raw driver values and byte hashing are deliberate non-filterable integrity boundaries. */
    private function query(string $sql, array $parameters = []): array
    {
        $statement = null;
        $result = null;
        try {
            $statement = @$this->database()->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException();
            }
            foreach ($parameters as $name => $value) {
                $type = $name === ':bytes' ? SQLITE3_BLOB : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
                if (!$statement->bindValue($name, $value, $type)) {
                    throw new RuntimeException();
                }
            }
            $result = @$statement->execute();
            if ($result === false) {
                throw new RuntimeException();
            }
            $rows = [];
            if ($result->numColumns() > 0) {
                while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                    $rows[] = $row;
                }
            }
            return $rows;
        } catch (Throwable) {
            throw new RuntimeException('repos_storage_unavailable');
        } finally {
            if ($result instanceof \SQLite3Result) {
                $result->finalize();
            }
            if ($statement instanceof \SQLite3Stmt) {
                $statement->close();
            }
        }
    }
}
