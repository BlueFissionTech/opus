<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;

final class CreateApplicationIntakes extends Delta
{
    public function change()
    {
        Scaffold::create('application_intakes', function (Structure $entity): void {
            $entity->incrementer('application_intake_id');
            $entity->text('session_key', 64)->unique();
            $entity->text('tenant_id', 128)->null();
            $entity->text('application_slug', 191);
            $entity->text('prompt_version', 64);
            $entity->text('status', 32);
            $entity->text('answers', 65535);
            $entity->text('defaults', 65535);
            $entity->text('skipped', 65535);
            $entity->text('actor', 65535);
            $entity->text('correlation_id', 191)->null();
            $entity->numeric('revision', 11)->default(1);
            $entity->text('session_created_at', 32);
            $entity->text('session_updated_at', 32);
            $entity->text('completed_at', 32)->null();
            $entity->timestamps();
            $entity->comment('Resumable application and central-agent intake sessions.');
        });
    }

    public function revert()
    {
        Scaffold::delete('application_intakes');
    }
}
