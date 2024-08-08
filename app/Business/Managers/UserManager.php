<?php

namespace App\Business\Managers;

use BlueFission\Services\Service;
use BlueFission\Connections\Database\MySQLLink;
use App\Domain\User\Repositories\IUserRepository;
use App\Domain\User\Repositories\ICredentialRepository;
use App\Domain\User\User;
use App\Domain\User\Credential;

class UserManager extends Service
{
    protected $_users;
    protected $_credentials;

    public function __construct(IUserRepository $users, ICredentialRepository $credentials)
    {
        $this->_users = $users;
        $this->_credentials = $credentials;
        parent::__construct();
    }

    public function create(array $userData)
    {
        $user = new User;
        $credential = new Credential;

        $user->realname = $userData['realname'];
        $user->displayname = $userData['displayname'];

        $credential->username = $userData['username'];
        $credential->password = $userData['password'];

        $credential->password = password_hash($credential->password, PASSWORD_DEFAULT);

        $this->_users->save($user);
        
        $credential->user_id = $this->_users->lastInsertId();
        $this->_credentials->save($credential);

        tell($this->_credentials->status());
    }

    public function changePassword($username, $newPassword)
    {
        $credential = new CredentialModel($this->_link);

        $credential->username = $username;
        $credential->read();

        if (!$credential->id()) {
            tell("User not found.");
            return;
        }

        $credential->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $credential->write();

        tell($credential->status());
    }
}
