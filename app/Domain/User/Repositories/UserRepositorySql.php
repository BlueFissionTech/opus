<?php
namespace App\Domain\User\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\User\Repositories\IUserRepository;
use App\Domain\User\Models\UserModel as Model;
use App\Domain\User\User;

class UserRepositorySql extends RepositorySql implements IUserRepository
{
    // protected $_db;
    protected $_name = "users";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($user_id)
    {
        $this->_model->user_id = $user_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(User $user)
    {
        $this->_model->assign($user);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($user_id)
    {
        $this->_model->id($user_id);
        $this->_model->delete();
    }
}