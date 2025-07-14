<?php
namespace App\Business\Console;

use BlueFission\Services\Service;
use BlueFission\Behavioral\IDispatcher;
use BlueFission\BlueCore\Business\Managers\DatasourceManager;


class DatabaseManager extends Service implements IDispatcher {

	private $_mgr;
	public function __construct( DatasourceManager $datasourceManager )
    {
		parent::__construct();
		$this->_mgr = $datasourceManager;
	}

	public function runMigrations()
	{
		$deltas = $this->_mgr->runMigrations();
	}

	public function revertMigrations()
	{
		$deltas = $this->_mgr->revertMigrations();
	}

	public function populate( $args )
	{
		$arg = $args->context['data'] ?: null;
		$auto = false;
		if ($arg == 'auto') {
			$auto = true;
		}
		$this->_mgr->populate($auto);
	}
}