<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IInitiativeRepository;
use App\Domain\Initiative\Models\InitiativeModel as Model;
use App\Domain\Initiative\Models\InitiativeToKpiTypeModel as KpiTypeModel;
use App\Domain\Initiative\Initiative;
use App\Domain\Initiative\InitiativeToKpiType;

class InitiativeRepositorySql extends RepositorySql implements IInitiativeRepository
{
    // protected $_db;
    protected $_name = "initiatives";
    private $_kpi_type_model;

    public function __construct(MySQLLink $link, Model $model, KpiTypeModel $kpi_type_model)
    {
        parent::__construct($link, $model);
        $this->_kpi_type_model = $kpi_type_model;
    }

    public function find($initiative_id)
    {
        $this->_model->initiative_id = $initiative_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Initiative $initiative)
    {
        $this->_model->assign($initiative);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($initiative_id)
    {
        $this->_model->initiative_id = $initiative_id;
        $this->_model->delete();
    }

    public function addKpiType(InitiativeToKpiType $initiative_to_kpi_type) {
        $this->_kpi_type_model->assign($initiative_to_kpi_type);
        $this->_kpi_type_model->write();

        return $this->_kpi_type_model->response();
    }
}