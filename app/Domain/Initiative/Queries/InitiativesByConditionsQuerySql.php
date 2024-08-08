<?php
namespace App\Domain\Initiative\Queries;

use BlueFission\Connections\Database\MySQLLink;
use App\Domain\Initiative\Models\InitiativeModel as Model;
use App\Domain\Initiative\Queries\IInitiativesByConditionsQuery;

class InitiativesByConditionsQuerySql implements IInitiativesByConditionsQuery {
	private $_model;

	public function __construct( MySQLLink $link, Model $model )
	{
		$link->open();

		$this->_model = $model;
	}

	public function fetch($user_id) 
	{
		$model = $this->_model;
		
		$model->read();
		$data = $model->result()->toArray();
		return $data;
	}
}