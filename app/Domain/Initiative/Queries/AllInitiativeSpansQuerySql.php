<?php
namespace App\Domain\Initiative\Queries;

use BlueFission\Connections\Database\MySQLLink;
use App\Domain\Initiative\Models\InitiativeSpanModel as Model;

use App\Domain\Initiative\Queries\IAllInitiativeSpansQuery;

class AllInitiativeSpansQuerySql implements IAllInitiativeSpansQuery {
	private $_model;

	public function __construct( MySQLLink $link, Model $model )
	{
		$link->open();

		$this->_model = $model;
	}

	public function fetch() 
	{
		$model = $this->_model;
		$model->read();
		$data = $model->result()->toArray();
		return $data;
	}
}