<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;

class ScaffoldCommunicationTables extends Delta
{
    public function change()
    {
        Scaffold::create('communication_statuses', function (Structure $entity) {
            $entity->incrementer('communication_status_id');
            $entity->text('name');
            $entity->text('label')->null();
            $entity->timestamps();
            $entity->comment("The status for communications (unsent, sent, delivered, read, etc).");
        });

        Scaffold::create('communication_types', function (Structure $entity) {
            $entity->incrementer('communication_type_id');
            $entity->text('name');
            $entity->text('label')->null();
            $entity->timestamps();
            $entity->comment("The types of communications (status, update, notice, warning, etc).");
        });

        Scaffold::create('communication_channels', function (Structure $entity) {
            $entity->incrementer('communication_channel_id');
            $entity->text('name');
            $entity->text('label')->null();
            $entity->text('description')->null();
            $entity->timestamps();
            $entity->comment("The channels for communication (botman, HTTP, SMS, email, etc).");
        });

        Scaffold::create('communications', function (Structure $entity) {
            $entity->incrementer('communication_id');
            $entity->numeric('user_id')->foreign('users', 'user_id');
            $entity->numeric('recipient_id')->foreign('users', 'user_id');
            $entity->numeric('communication_type_id')->foreign('communication_types', 'communication_type_id');
            $entity->text('channel')->null();
            $entity->text('content');
            $entity->numeric('communication_status_id')->foreign('communication_statuses', 'communication_status_id');
            $entity->timestamps();
            $entity->comment("The table holding all of the application's communications.");
        });

        Scaffold::create('communication_parameters', function (Structure $entity) {
            $entity->incrementer('communication_parameter_id');
            $entity->numeric('communication_id')->foreign('communications', 'communication_id');
            $entity->text('name');
            $entity->text('value')->null();
            $entity->timestamps();
            $entity->comment("Parameters for communications.");
        });

        Scaffold::create('communication_attachments', function (Structure $entity) {
            $entity->incrementer('communication_attachment_id');
            $entity->numeric('communication_id')->foreign('communications', 'communication_id');
            $entity->text('name');
            $entity->text('file_type');
            $entity->text('file_path');
            $entity->timestamps();
            $entity->comment("Attachments for communications.");
        });
    }

    public function revert()
    {
        Scaffold::delete('communication_attachments');
        Scaffold::delete('communication_parameters');
        Scaffold::delete('communications');
        Scaffold::delete('communication_channels');
        Scaffold::delete('communication_types');
        Scaffold::delete('communication_statuses');
    }
}
