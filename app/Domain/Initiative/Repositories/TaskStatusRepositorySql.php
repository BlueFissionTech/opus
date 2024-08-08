<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\ITaskStatusRepository;
use App\Domain\Initiative\Models\TaskStatusModel as Model;
use App\Domain\Initiative\TaskStatus;

class TaskStatusRepositorySql extends RepositorySql implements ITaskStatusRepository
{
    // protected $_db;
    protected $_name = "task_statuses";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($task_status_id)
    {
        $this->_model->task_status_id = $task_status_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(TaskStatus $task_status)
    {
        $this->_model->assign($task_status);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($task_status_id)
    {
        $this->_model->task_status_id = $task_status_id;
        $this->_model->delete();
    }
}