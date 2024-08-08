<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IAttributeRepository;
use App\Domain\Initiative\Models\AttributeModel as Model;
use App\Domain\Initiative\Attribute;

class AttributeRepositorySql extends RepositorySql implements IAttributeRepository
{
    // protected $_db;
    protected $_name = "attributes";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($attribute_id)
    {
        $this->_model->attribute_id = $attribute_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(Attribute $attribute)
    {
        $this->_model->assign($attribute);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($attribute_id)
    {
        $this->_model->attribute_id = $attribute_id;
        $this->_model->delete();
    }
}