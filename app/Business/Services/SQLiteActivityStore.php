<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Activity\ActivityEvent;
use App\Domain\Activity\ActivityScope;
use App\Domain\Activity\IActivityStore;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use InvalidArgumentException;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use Throwable;

final class SQLiteActivityStore implements IActivityStore
{
    public function __construct(private SQLiteLink $link) {}

    /** Explicit operator installation step; construction never migrates a database. */
    public function initialize(): void
    {
        $this->query('CREATE TABLE IF NOT EXISTS opus_activity (
            sequence INTEGER PRIMARY KEY AUTOINCREMENT,
            scope_sequence INTEGER NOT NULL,
            event_id TEXT NOT NULL UNIQUE,
            application_id TEXT NOT NULL,
            tenant_id TEXT,
            actor_id TEXT NOT NULL,
            correlation_id TEXT NOT NULL,
            approval_reference TEXT,
            receipt TEXT NOT NULL
        )')->finalize();
        $this->query('CREATE INDEX IF NOT EXISTS opus_activity_scope ON opus_activity
            (application_id, tenant_id, actor_id, scope_sequence)')->finalize();
        $this->query("CREATE UNIQUE INDEX IF NOT EXISTS opus_activity_order ON opus_activity
            (application_id, actor_id, COALESCE(tenant_id, ''), (tenant_id IS NULL), scope_sequence)")->finalize();
        $this->query('CREATE INDEX IF NOT EXISTS opus_activity_approval ON opus_activity
            (application_id, tenant_id, actor_id, approval_reference)')->finalize();
    }

    public function append(ActivityEvent $event, ?string $approvalReference = null): void
    {
        $receipt = HTTP::jsonEncode($event->toArray());
        if (!Str::is($receipt) || Str::len($receipt) > 16384) {
            throw new RuntimeException('activity_receipt_invalid');
        }
        $this->query('INSERT INTO opus_activity
            (event_id, application_id, tenant_id, actor_id, correlation_id, approval_reference, receipt, scope_sequence)
            SELECT :id, :application, :tenant, :actor, :correlation, :approval, :receipt,
                COALESCE(MAX(scope_sequence), 0) + 1 FROM opus_activity
            WHERE application_id = :application AND tenant_id IS :tenant AND actor_id = :actor', [
                ...$this->scope($event->scope), ':id' => $event->id,
                ':correlation' => $event->correlationId, ':approval' => $approvalReference,
                ':receipt' => $receipt,
            ])->finalize();
    }

    public function approval(ActivityScope $scope, string $reference): ?ActivityEvent
    {
        $result = $this->query('SELECT receipt FROM opus_activity WHERE application_id = :application
            AND tenant_id IS :tenant AND actor_id = :actor AND approval_reference = :approval
            ORDER BY scope_sequence DESC LIMIT 1', [...$this->scope($scope), ':approval' => $reference]);
        try {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $event = $row === false ? null : $this->hydrate($row['receipt']);
            return $event !== null && $event->scope->matches($scope) && $event->phase === 'approval_requested' ? $event : null;
        } finally {
            $result->finalize();
        }
    }

    public function read(ActivityScope $scope, int $after = 0, int $limit = 100, ?string $correlation = null): array
    {
        if ($after < 0 || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('activity_page_invalid');
        }
        $params = [...$this->scope($scope), ':after' => $after, ':limit' => $limit];
        $filter = '';
        if ($correlation !== null) {
            $filter = ' AND correlation_id = :correlation';
            $params[':correlation'] = $correlation;
        }
        $result = $this->query('SELECT scope_sequence AS sequence, receipt FROM opus_activity WHERE application_id = :application
            AND tenant_id IS :tenant AND actor_id = :actor AND scope_sequence > :after' . $filter
            . ' ORDER BY scope_sequence LIMIT :limit', $params);
        $events = [];
        try {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $event = $this->hydrate($row['receipt']);
                if ($event->scope->matches($scope)) {
                    $events[] = ['sequence' => (int) $row['sequence'], 'event' => $event];
                }
            }
            return $events;
        } finally {
            $result->finalize();
        }
    }

    private function scope(ActivityScope $scope): array
    {
        return [':application' => $scope->applicationId, ':tenant' => $scope->tenantId, ':actor' => $scope->actorId];
    }

    private function hydrate(string $receipt): ActivityEvent
    {
        if (Str::len($receipt) > 16384) {
            throw new RuntimeException('activity_receipt_invalid');
        }
        $data = HTTP::jsonDecode($receipt);
        if (!Arr::is($data)) {
            throw new RuntimeException('activity_receipt_invalid');
        }
        return ActivityEvent::fromArray($data);
    }

    /** Native statement binding is the SQLite driver boundary, owned by SQLiteLink. */
    private function query(string $sql, array $params = []): SQLite3Result
    {
        try {
            $database = $this->link->connection();
            if (!$database instanceof SQLite3) {
                throw new RuntimeException('activity_connection_unavailable');
            }
            $statement = @$database->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('activity_statement_unavailable');
            }
            foreach ($params as $name => $value) {
                if (!$statement->bindValue($name, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT))) {
                    throw new RuntimeException('activity_binding_failed');
                }
            }
            $result = @$statement->execute();
            if (!$result instanceof SQLite3Result) {
                throw new RuntimeException('activity_query_failed');
            }
            return $result;
        } catch (Throwable) {
            throw new RuntimeException('activity_storage_unavailable');
        }
    }
}
