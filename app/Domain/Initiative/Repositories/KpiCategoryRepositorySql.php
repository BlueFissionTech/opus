<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IKpiCategoryRepository;
use App\Domain\Initiative\Models\KpiCategoryModel as Model;
use App\Domain\Initiative\KpiCategory;

class KpiCategoryRepositorySql extends RepositorySql implements IKpiCategoryRepository
{
    // protected $_db;
    protected $_name = "kpi_categories";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($kpi_category_id)
    {
        $this->_model->kpi_category_id = $kpi_category_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(KpiCategory $kpi_category)
    {
        $this->_model->assign($kpi_category);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($kpi_category_id)
    {
        $this->_model->kpi_category_id = $kpi_category_id;
        $this->_model->delete();
    }
}