<?php
declare(strict_types=1);
namespace AddOns\Versa\Domain;

use BlueFission\Obj;

final class DispatchResult extends Obj
{
    protected $_immutable = true;
    public function __construct(string $code, string $effects = 'none', ?string $correlation = null)
    {
        $this->_data = ['version' => 1, 'code' => $code, 'effects' => $effects,
            'delivery' => 'unknown', 'correlation_id' => $correlation, 'receipt_scope' => 'service_instance'];
        parent::__construct();
    }
}
