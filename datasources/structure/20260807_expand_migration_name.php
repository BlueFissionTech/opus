<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\MySQL;

class ExpandMigrationName extends Delta
{
    public function change()
    {
        $database = new MySQL(['location' => null, 'name' => 'migrations']);
        $database->activate();
        $database->run('ALTER TABLE `migrations` MODIFY `name` VARCHAR(255) NOT NULL');

        if ($database->status() !== MySQL::STATUS_SUCCESS) {
            throw new RuntimeException($database->error());
        }
    }

    public function revert()
    {
        // Migration names already persisted above 45 characters cannot be narrowed safely.
    }
}
