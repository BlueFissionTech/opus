<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IInitiativeTypeRepository;
use App\Domain\Initiative\Models\InitiativeTypeModel as Model;
use App\Domain\Initiative\InitiativeType;

class InitiativeTypeRepositorySql extends RepositorySql implements IInitiativeTypeRepository
{
    // protected $_db;
    protected $_name = "initiative_types";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($initiative_type_id)
    {
        $this->_model->initiative_type_id = $initiative_type_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(InitiativeType $initiative_type)
    {
        $this->_model->assign($initiative_type);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($initiative_type_id)
    {
        $this->_model->initiative_type_id = $initiative_type_id;
        $this->_model->delete();
    }
}