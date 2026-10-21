<?php

declare(strict_types=1);

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Data\Storage\Structure\SQLiteStructure;

/** Opt-in delta: bind the private SQLite connection before running this directory. */
final class CreateCommandActivity extends Delta
{
    public function __construct(private SQLiteLink $link) {}

    public function change()
    {
        $structure = new SQLiteStructure('opus_activity');
        $structure->incrementer('sequence');
        $structure->numeric('scope_sequence');
        $structure->text('event_id', 32)->unique();
        $structure->text('application_id', 256);
        $structure->text('tenant_id', 256)->null();
        $structure->text('actor_id', 256);
        $structure->text('correlation_id', 32);
        $structure->text('approval_reference', 64)->null();
        $structure->text('receipt', 16384);
        $this->transaction([
            $structure->build(),
            'CREATE INDEX opus_activity_scope ON opus_activity
                (application_id, tenant_id, actor_id, scope_sequence)',
            "CREATE UNIQUE INDEX opus_activity_order ON opus_activity
                (application_id, actor_id, COALESCE(tenant_id, ''), (tenant_id IS NULL), scope_sequence)",
            'CREATE INDEX opus_activity_approval ON opus_activity
                (application_id, tenant_id, actor_id, approval_reference)',
        ]);
    }

    public function revert()
    {
        $this->transaction(['DROP TABLE opus_activity']);
    }

    private function transaction(array $statements): void
    {
        $database = $this->link->connection();
        if (!$database instanceof SQLite3) {
            throw new RuntimeException('activity_migration_connection_unavailable');
        }
        $started = false;
        try {
            if (!@$database->exec('BEGIN IMMEDIATE')) {
                throw new RuntimeException('activity_migration_failed');
            }
            $started = true;
            foreach ($statements as $statement) {
                if (!@$database->exec($statement)) {
                    throw new RuntimeException('activity_migration_failed');
                }
            }
            if (!@$database->exec('COMMIT')) {
                throw new RuntimeException('activity_migration_failed');
            }
        } catch (Throwable) {
            if ($started) {
                @$database->exec('ROLLBACK');
            }
            throw new RuntimeException('activity_migration_failed');
        }
    }
}
