<?php
namespace App\Domain\Conversation;

use BlueFission\BlueCore\ValueObject;

class EntityToTags extends ValueObject {
	public $entity_id;
	public $tag_id;
	public $weight;
}