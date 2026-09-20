<?php
namespace App\Domain\Initiative;

use BlueFission\BlueCore\ValueObject;

class Kpi extends ValueObject {
	public $kpi_id;
	public $initiative_id;
	public $kpi_type_id;
	public $attribute_id;
	public $operator_id;
	public $unit_type_id;
	public $value;
}
