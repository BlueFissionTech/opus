<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IQuantifierRepository;
use App\Domain\Initiative\Models\QuantifierModel as Model;
use App\Domain\Initiative\Quantifier;

class QuantifierRepositorySql extends RepositorySql implements IQuantifierRepository
{
    // protected $_db;
    protected $_name = "quantifiers";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($quantifier_id)
    {
        $this->_model->quantifier_id = $quantifier_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Quantifier $quantifier)
    {
        $this->_model->assign($quantifier);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($quantifier_id)
    {
        $this->_model->quantifier_id = $quantifier_id;
        $this->_model->delete();
    }
}