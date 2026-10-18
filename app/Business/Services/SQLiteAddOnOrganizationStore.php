<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\AddOn\IAddOnOrganizationStore;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Services\Service;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use Throwable;

final class SQLiteAddOnOrganizationStore extends Service implements IAddOnOrganizationStore
{
    public function __construct(private SQLiteLink $link)
    {
        parent::__construct();
    }

    public function load(string $scope): array
    {
        $result = $this->query('SELECT revision, document FROM opus_addon_organization WHERE scope = :scope', [':scope' => $scope]);
        try {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row === false) {
                return ['revision' => 0, 'groups' => [], 'tags' => []];
            }
            $state = $this->decode($row['document']);
            if (!Arr::is($state['groups'] ?? null) || !Arr::is($state['tags'] ?? null)) {
                throw new RuntimeException('organization_document_invalid');
            }
            return ['revision' => (int) $row['revision'], 'groups' => $state['groups'], 'tags' => $state['tags']];
        } finally {
            $result->finalize();
        }
    }

    public function save(string $scope, int $expectedRevision, array $state, array $audit): int
    {
        if ($expectedRevision < 0) {
            throw new RuntimeException('organization_revision_conflict');
        }
        $document = $this->encode(['groups' => $state['groups'], 'tags' => $state['tags']]);
        $receipt = $this->encode($audit);
        $database = $this->database();
        $started = false;
        try {
            $this->query('BEGIN IMMEDIATE')->finalize();
            $started = true;
            if ($this->load($scope)['revision'] !== $expectedRevision) {
                throw new RuntimeException('organization_revision_conflict');
            }
            $revision = $expectedRevision + 1;
            $this->query('INSERT INTO opus_addon_organization (scope, revision, document)
                VALUES (:scope, :revision, :document)
                ON CONFLICT(scope) DO UPDATE SET revision = excluded.revision, document = excluded.document', [
                    ':scope' => $scope, ':revision' => $revision, ':document' => $document,
                ])->finalize();
            $this->query('INSERT INTO opus_addon_organization_audit (scope, revision, receipt)
                VALUES (:scope, :revision, :receipt)', [
                    ':scope' => $scope, ':revision' => $revision, ':receipt' => $receipt,
                ])->finalize();
            $this->query('COMMIT')->finalize();
            return $revision;
        } catch (Throwable $error) {
            if ($started) {
                @$database->exec('ROLLBACK');
            }
            throw new RuntimeException($error->getMessage() === 'organization_revision_conflict'
                ? 'organization_revision_conflict' : 'organization_storage_unavailable');
        }
    }

    public function history(string $scope, int $after = 0, int $limit = 100): array
    {
        if ($after < 0 || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('organization_page_invalid');
        }
        $result = $this->query('SELECT revision, receipt FROM opus_addon_organization_audit
            WHERE scope = :scope AND revision > :after ORDER BY revision LIMIT :limit', [
                ':scope' => $scope, ':after' => $after, ':limit' => $limit,
            ]);
        try {
            $records = [];
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $records[] = ['revision' => (int) $row['revision'], 'receipt' => $this->decode($row['receipt'])];
            }
            return $records;
        } finally {
            $result->finalize();
        }
    }

    private function encode(array $data): string
    {
        // Check the package helper result: malformed UTF-8 must fail, not disappear.
        $json = HTTP::jsonEncode($data);
        if (!Str::is($json)) {
            throw new RuntimeException('organization_document_invalid');
        }
        if (strlen($json) > 1048576) {
            throw new RuntimeException('organization_document_too_large');
        }
        return $json;
    }

    private function decode(string $json): array
    {
        try {
            if (strlen($json) > 1048576) {
                throw new RuntimeException();
            }
            $data = HTTP::jsonDecode($json);
            if (!Arr::is($data)) {
                throw new RuntimeException();
            }
            return $data;
        } catch (Throwable) {
            throw new RuntimeException('organization_document_invalid');
        }
    }

    private function database(): SQLite3
    {
        $database = $this->link->connection();
        if (!$database instanceof SQLite3) {
            throw new RuntimeException('organization_storage_unavailable');
        }
        return $database;
    }

    /** Prepared statements are the driver boundary of the host-owned SQLiteLink. */
    private function query(string $sql, array $params = []): SQLite3Result
    {
        try {
            $statement = @$this->database()->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException();
            }
            foreach ($params as $key => $value) {
                if (!$statement->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT)) {
                    throw new RuntimeException();
                }
            }
            $result = @$statement->execute();
            if (!$result instanceof SQLite3Result) {
                throw new RuntimeException();
            }
            return $result;
        } catch (Throwable) {
            throw new RuntimeException('organization_storage_unavailable');
        }
    }
}
