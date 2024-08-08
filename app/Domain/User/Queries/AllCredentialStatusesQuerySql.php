<?php
namespace App\Domain\User\Queries;

use BlueFission\Connections\Database\MySQLLink;
use App\Domain\User\Models\CredentialStatusModel as Model;

use App\Domain\User\Queries\IAllCredentialStatusesQuery;

class AllCredentialStatusesQuerySql implements IAllCredentialStatusesQuery {
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