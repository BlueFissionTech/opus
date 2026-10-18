<?php

declare(strict_types=1);

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Data\Storage\Structure\SQLiteStructure;

/** Run through the configured DatasourceManager with the same private SQLiteLink as the store. */
final class CreateAddOnOrganization extends Delta
{
    public function __construct(private SQLiteLink $link) {}

    public function change()
    {
        $state = new SQLiteStructure('opus_addon_organization');
        $state->text('scope', 1024)->unique();
        $state->numeric('revision');
        $state->text('document', 1048576);
        $audit = new SQLiteStructure('opus_addon_organization_audit');
        $audit->text('scope', 1024);
        $audit->numeric('revision');
        $audit->text('receipt', 1048576);
        $this->transaction([
            $state->build(), $audit->build(),
            'CREATE UNIQUE INDEX opus_addon_organization_audit_order ON opus_addon_organization_audit (scope, revision)',
        ]);
    }

    public function revert()
    {
        $this->transaction(['DROP TABLE opus_addon_organization_audit', 'DROP TABLE opus_addon_organization']);
    }

    private function transaction(array $statements): void
    {
        $database = $this->link->connection();
        if (!$database instanceof SQLite3) {
            throw new RuntimeException('organization_migration_connection_unavailable');
        }
        $started = false;
        try {
            if (!@$database->exec('BEGIN IMMEDIATE')) {
                throw new RuntimeException();
            }
            $started = true;
            foreach ($statements as $statement) {
                if (!@$database->exec($statement)) {
                    throw new RuntimeException();
                }
            }
            if (!@$database->exec('COMMIT')) {
                throw new RuntimeException();
            }
        } catch (Throwable) {
            if ($started) {
                @$database->exec('ROLLBACK');
            }
            throw new RuntimeException('organization_migration_failed');
        }
    }
}
