<?php
declare(strict_types=1);
namespace AddOns\Meridia\Domain;

use BlueFission\Obj;

class ResolutionResult extends Obj
{
    protected $_immutable = true;
    public function __construct(
        public readonly string $status,
        public readonly ?RouteCandidate $candidate = null
    ) {
        $this->_data = ['version' => 1, 'status' => $status, 'effects' => 'none',
            'candidate' => $candidate?->toArray()];
        parent::__construct();
    }
}
