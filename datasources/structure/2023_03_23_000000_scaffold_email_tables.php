<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;

class ScaffoldEmailTables extends Delta
{
    public function change()
    {
        Scaffold::create('email_accounts', function (Structure $entity) {
            $entity->incrementer('account_id');
            $entity->text('email_address');
            $entity->text('name')->null();
            $entity->text('smtp_host');
            $entity->numeric('smtp_port');
            $entity->text('smtp_user');
            $entity->text('smtp_pass');
            $entity->text('smtp_encryption')->null();
            $entity->text('imap_host');
            $entity->numeric('imap_port');
            $entity->text('imap_user');
            $entity->text('imap_pass');
            $entity->text('imap_encryption')->null();
            $entity->timestamps();
            $entity->comment("Email accounts for sending and receiving emails.");
        });

        Scaffold::create('emails', function (Structure $entity) {
            $entity->incrementer('email_id');
            $entity->numeric('account_id')->foreign('email_accounts', 'account_id');
            $entity->text('from');
            $entity->text('to');
            $entity->text('cc')->null();
            $entity->text('bcc')->null();
            $entity->text('subject');
            $entity->text('body', 10240);
            $entity->text('headers', 2048)->null();
            $entity->numeric('status')->default(0);
            $entity->timestamps();
            $entity->comment("Emails with their content and metadata.");
        });

        Scaffold::create('email_attachments', function (Structure $entity) {
            $entity->incrementer('attachment_id');
            $entity->numeric('email_id')->foreign('emails', 'email_id');
            $entity->text('file_name');
            $entity->text('file_type');
            $entity->text('file_path');
            $entity->timestamps();
            $entity->comment("Attachments for emails.");
        });
    }

    public function revert()
    {
        Scaffold::delete('email_attachments');
        Scaffold::delete('emails');
        Scaffold::delete('email_accounts');
    }
}
