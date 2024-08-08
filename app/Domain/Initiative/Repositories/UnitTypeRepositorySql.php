<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IUnitTypeRepository;
use App\Domain\Initiative\Models\UnitTypeModel as Model;
use App\Domain\Initiative\UnitType;

class UnitTypeRepositorySql extends RepositorySql implements IUnitTypeRepository
{
    // protected $_db;
    protected $_name = "unit_types";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($unit_type_id)
    {
        $this->_model->unit_type_id = $unit_type_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(UnitType $unit_type)
    {
        $this->_model->assign($unit_type);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($unit_type_id)
    {
        $this->_model->unit_type_id = $unit_type_id;
        $this->_model->delete();
    }
}