<?php
declare(strict_types=1);
namespace AddOns\Versa\Domain;

use BlueFission\Obj;

/** Only the host review callback may produce approval, bound to one exact request. */
final class SendApproval extends Obj
{
    protected $_immutable = true;
    public function __construct(public readonly SendRequest $request, public readonly string $application,
        public readonly string $tenant, public readonly string $actor, public readonly string $correlation)
    {
        $this->_data = ['version' => 1];
        parent::__construct();
    }
}
