<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IConditionRepository;
use App\Domain\Initiative\Models\ConditionModel as Model;
use App\Domain\Initiative\Condition;

class ConditionRepositorySql extends RepositorySql implements IConditionRepository
{
    // protected $_db;
    protected $_name = "conditions";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($condition_id)
    {
        $this->_model->condition_id = $condition_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Condition $condition)
    {
        $this->_model->assign($condition);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($condition_id)
    {
        $this->_model->condition_id = $condition_id;
        $this->_model->delete();
    }
}