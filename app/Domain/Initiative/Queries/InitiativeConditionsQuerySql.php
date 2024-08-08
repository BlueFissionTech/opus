<?php
namespace App\Domain\Initiative\Queries;

use BlueFission\Connections\Database\MySQLLink;
use App\Domain\Initiative\Models\ConditionModel as Model;

use App\Domain\Initiative\Queries\IInitiativeConditionsQuery;

class InitiativeConditionsQuerySql implements IInitiativeConditionsQuery {
	private $_model;

	public function __construct( MySQLLink $link, Model $model )
	{
		$link->open();

		$this->_model = $model;
	}

	public function fetch($initiative_id) 
	{
		$model = $this->_model;
		$model->initiative_id = $initiative_id;
		$model->read();
		$data = $model->result()->toArray();
		return $data;
	}
}