<?php
namespace App\Domain\Initiative\Repositories;

use BlueFission\Connections\Database\MySQLLink;
use BlueFission\BlueCore\Repository\RepositorySql;
use App\Domain\Initiative\Repositories\IInitiativePrivacyTypeRepository;
use App\Domain\Initiative\Models\InitiativePrivacyTypeModel as Model;
use App\Domain\Initiative\InitiativePrivacyType;

class InitiativePrivacyTypeRepositorySql extends RepositorySql implements IInitiativePrivacyTypeRepository
{
    // protected $_db;
    protected $_name = "initiative_privacy_types";

    public function __construct(MySQLLink $link, Model $model)
    {
        parent::__construct($link, $model);
    }

    public function find($initiative_privacy_type_id)
    {
        $this->_model->initiative_privacy_type_id = $initiative_privacy_type_id;
        $this->_model->read();

        return $this->_model->response();
    }

    public function save(InitiativePrivacyType $initiative_privacy_type)
    {
        $this->_model->assign($initiative_privacy_type);
        $this->_model->write();

        return $this->_model->response();
    }

    public function remove($initiative_privacy_type_id)
    {
        $this->_model->initiative_privacy_type_id = $initiative_privacy_type_id;
        $this->_model->delete();
    }
}