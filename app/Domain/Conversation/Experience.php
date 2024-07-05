<?php
namespace App\Domain\Conversation;

use BlueFission\BlueCore\ValueObject;

class Experience extends ValueObject {
	public $exprience_id;
	public $input;
	public $timestamp;
	public $data;
}