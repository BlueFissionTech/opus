<?php
namespace App\Domain\Initiative\Queries;

use BlueFission\Connections\Database\MySQLLink;
use App\Domain\Initiative\Models\InitiativeModel as Model;

use App\Domain\Initiative\Queries\IInitiativesByUserQuery;

class InitiativesByUserQuerySql implements IInitiativesByUserQuery {
	private $_model;

	public function __construct( MySQLLink $link, Model $model )
	{
		$link->open();

		$this->_model = $model;
	}

	public function fetch($user_id) 
	{
		$model = $this->_model;
		// $model->user_id = $user_id;
		$model->read();
		$data = $model->result()->toArray();
		return $data;
	}
}