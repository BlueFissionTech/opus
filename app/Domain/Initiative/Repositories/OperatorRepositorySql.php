<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IOperatorRepository;
use App\Domain\Initiative\Models\OperatorModel as Model;
use App\Domain\Initiative\Operator;

class OperatorRepositorySql extends RepositorySql implements IOperatorRepository
{
    // protected $_db;
    protected $_name = "operators";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($operator_id)
    {
        $this->_model->operator_id = $operator_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Operator $operator)
    {
        $this->_model->assign($operator);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($operator_id)
    {
        $this->_model->operator_id = $operator_id;
        $this->_model->delete();
    }
}