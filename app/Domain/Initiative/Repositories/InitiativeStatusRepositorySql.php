<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IInitiativeStatusRepository;
use App\Domain\Initiative\Models\InitiativeStatusModel as Model;
use App\Domain\Initiative\InitiativeStatus;

class InitiativeStatusRepositorySql extends RepositorySql implements IInitiativeStatusRepository
{
    // protected $_db;
    protected $_name = "initiative_statuses";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($initiative_status_id)
    {
        $this->_model->initiative_status_id = $initiative_status_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(InitiativeStatus $initiative_status)
    {
        $this->_model->assign($initiative_status);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($initiative_status_id)
    {
        $this->_model->initiative_status_id = $initiative_status_id;
        $this->_model->delete();
    }
}