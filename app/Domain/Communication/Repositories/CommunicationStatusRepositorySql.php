<?php
namespace App\Domain\Communication\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Framework\Repository\RepositorySql;
use App\Domain\Communication\Repositories\ICommunicationStatusRepository;
use App\Domain\Communication\Models\CommunicationStatusModel as Model;
use App\Domain\Communication\CommunicationStatus;

class CommunicationStatusRepositorySql extends RepositorySql implements ICommunicationStatusRepository
{
    protected $_name = "communication_statuses";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($communication_status_id)
    {
        $this->_model->assign(['communication_status_id' => $communication_status_id]);
        $this->_model->read();

        return $this->_model->response();
    }

    public function findByName($name)
    {
        $this->_model->assign(['name' => $name]);
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(CommunicationStatus $communication_status)
    {
        $this->_model->assign($communication_status);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($communication_status_id)
    {
        $this->_model->assign(['communication_status_id' => $communication_status_id]);
        $this->_model->delete();
    }
}