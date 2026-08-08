<?php
namespace App\Business\Console;

use BlueFission\Arr;
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
		$this->_mgr->runMigrations();
	}

	public function revertMigrations()
	{
		$this->_mgr->revertMigrations();
	}

	public function populate( $args )
	{
		$data = Arr::getPath((array)($args->context ?? []), 'data', []);
		$arguments = Arr::is($data) ? $data : [$data];

		$this->_mgr->populate(Arr::contains($arguments, 'auto'));
	}
}
