<?php
namespace App\Domain\User\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\User\Repositories\ICredentialRepository;
use App\Domain\User\Models\CredentialModel as Model;
use App\Domain\User\Credential;

class CredentialRepositorySql extends RepositorySql implements ICredentialRepository
{
    // protected $_db;
    protected $_name = "credentials";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($credential_id)
    {
        $this->_model->credential_id = $credential_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Credential $credential)
    {
        $this->_model->assign($credential);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($credential_id)
    {
        $this->_model->id($credential_id);
        $this->_model->delete();
    }

    public function status()
    {
        return $this->_model->status();
    }
}