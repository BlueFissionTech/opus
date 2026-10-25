<?php
declare(strict_types=1);
namespace AddOns\Versa\Domain;

use BlueFission\Obj;

/** Trusted host configuration, never deserialized from a command or webhook. */
final class Channel extends Obj
{
    protected $_immutable = true;
    public function __construct(public readonly string $application, public readonly string $tenant,
        public readonly string $id, public readonly array $participants, public readonly bool $notifications = false)
    {
        $this->_data = ['application' => $application, 'tenant' => $tenant, 'id' => $id,
            'participants' => $participants, 'notifications' => $notifications];
        parent::__construct();
    }
}
