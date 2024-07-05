<?php
namespace App\Domain\Conversation;

use BlueFission\BlueCore\ValueObject;

class FactType extends ValueObject {
	public $fact_type_id;
	public $name;
	public $label;
}