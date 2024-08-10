<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;

class ScaffoldAddOnTable extends Delta
{
    public function change()
    {
        Scaffold::create('addons', function (Structure $entity) {
            $entity->incrementer('addon_id');
            $entity->text('name');
            $entity->text('version');
            $entity->numeric('is_active', 1)->default(0);
            $entity->text('primary_file');
            $entity->text('description', 2048)->null();
            $entity->text('namespace', 1024);
            $entity->text('path', 1024);
            $entity->timestamps();
            $entity->comment("The table holding all of the application's addons.");
        });
    }

    public function revert()
    {
        Scaffold::delete('addons');
    }
}
