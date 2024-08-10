<?php

use BlueFission\BlueCore\Datasource\Delta;
use BlueFission\Data\Storage\Structure\MySQLStructure as Structure;
use BlueFission\Data\Storage\Structure\MySQLScaffold as Scaffold;

class ScaffoldOAuthAndMultiFactorAuthTables extends Delta
{
    public function change()
    {
        // OAuthAuthenticator table
        Scaffold::create('oauth_authenticators', function (Structure $entity) {
            $entity->incrementer('oauth_authenticator_id');
            $entity->numeric('user_id')->foreign('users', 'user_id');
            $entity->text('provider');
            $entity->text('oauth_token');
            $entity->timestamps();
            $entity->comment("The table holding OAuth authenticator details.");
        });

        // MultiFactorAuthenticator table
        Scaffold::create('multi_factor_authenticators', function (Structure $entity) {
            $entity->incrementer('multi_factor_authenticator_id');
            $entity->numeric('user_id')->foreign('users', 'user_id');
            $entity->text('multi_factor_type');
            $entity->text('multi_factor_secret')->null();
            $entity->text('multi_factor_backup_codes')->null();
            $entity->numeric('multi_factor_enabled', 1)->default(0);
            $entity->timestamps();
            $entity->comment("The table holding multi-factor authenticator details.");
        });
    }

    public function revert()
    {
        Scaffold::delete('multi_factor_authenticators');
        Scaffold::delete('oauth_authenticators');
    }
}
