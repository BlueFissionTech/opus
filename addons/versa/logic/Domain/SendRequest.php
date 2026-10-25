<?php
declare(strict_types=1);
namespace AddOns\Versa\Domain;

use BlueFission\Obj;

final class SendRequest extends Obj
{
    protected $_immutable = true;
    public function __construct(public readonly string $channel, public readonly string $id,
        public readonly string $text, public readonly array $media = [], public readonly bool $notification = false)
    {
        $this->_data = ['channel' => $channel, 'id' => $id, 'text' => $text,
            'media' => $media, 'notification' => $notification];
        parent::__construct();
    }
}
