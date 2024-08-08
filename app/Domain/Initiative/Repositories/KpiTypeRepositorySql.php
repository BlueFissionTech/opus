<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IKpiTypeRepository;
use App\Domain\Initiative\Models\KpiTypeModel as Model;
use App\Domain\Initiative\KpiType;

class KpiTypeRepositorySql extends RepositorySql implements IKpiTypeRepository
{
    // protected $_db;
    protected $_name = "kpi_types";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($kpi_type_id)
    {
        $this->_model->kpi_type_id = $kpi_type_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(KpiType $kpi_type)
    {
        $this->_model->assign($kpi_type);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($kpi_type_id)
    {
        $this->_model->kpi_type_id = $kpi_type_id;
        $this->_model->delete();
    }
}