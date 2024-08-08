<?php
namespace App\Business;

use BlueFission\Services\Service;
use BlueFission\Connections\Database\MySQLLink;

class MysqlConnector extends Service
{
	private $_link;

	public function __construct( MySQLLink $link )
	{
		$this->_link = $link;
		$this->_link->open();
		
		parent::__construct();
	}

	public function open( )
	{
		$this->_link->open();
	}
}