<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IPrerequisiteRepository;
use App\Domain\Initiative\Models\PrerequisiteModel as Model;
use App\Domain\Initiative\Prerequisite;

class PrerequisiteRepositorySql extends RepositorySql implements IPrerequisiteRepository
{
    // protected $_db;
    protected $_name = "prerequisites";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($prerequisite_id)
    {
        $this->_model->prerequisite_id = $prerequisite_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Prerequisite $prerequisite)
    {
        $this->_model->assign($prerequisite);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($prerequisite_id)
    {
        $this->_model->prerequisite_id = $prerequisite_id;
        $this->_model->delete();
    }
}