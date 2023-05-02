<?php
namespace App\Domain\Conversation\Repositories;

use BlueFission\Connections\Database\MysqlLink;
use BlueFission\Framework\Repository\RepositorySql;
use App\Domain\Conversation\Repositories\IFactRepository;
use App\Domain\Conversation\Models\FactModel as Model;
use App\Domain\Conversation\Fact;

class FactRepositorySql extends RepositorySql implements IFactRepository
{
    protected $_name = "facts";

    public function __construct(MysqlLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($fact_id)
    {
        $this->_model->read(['fact_id'=>$fact_id]);

        return $this->_model->response();
    }

    public function save(Fact $fact)
    {
        $this->_model->write($fact);

        return $this->_model->response();
    }

    public function remove($fact_id)
    {
        $this->_model->delete(['fact_id'=>$fact_id]);
    }
}