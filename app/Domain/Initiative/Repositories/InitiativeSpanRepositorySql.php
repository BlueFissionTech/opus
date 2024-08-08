<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IInitiativeSpanRepository;
use App\Domain\Initiative\Models\InitiativeSpanModel as Model;
use App\Domain\Initiative\InitiativeSpan;

class InitiativeSpanRepositorySql extends RepositorySql implements IInitiativeSpanRepository
{
    // protected $_db;
    protected $_name = "initiative_spans";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($initiative_span_id)
    {
        $this->_model->initiative_span_id = $initiative_span_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(InitiativeSpan $initiative_span)
    {
        $this->_model->assign($initiative_span);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($initiative_span_id)
    {
        $this->_model->initiative_span_id = $initiative_span_id;
        $this->_model->delete();
    }
}