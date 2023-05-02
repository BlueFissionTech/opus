<?php
namespace App\Domain\Communication;

use BlueFission\Framework\ValueObject;

class CommunicationChannel extends ValueObject
{
    public $communication_channel_id;
    public $name;
    public $label;
    public $description;
}

