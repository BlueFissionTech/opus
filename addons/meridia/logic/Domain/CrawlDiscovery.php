<?php
declare(strict_types=1);
namespace AddOns\Meridia\Domain;

use BlueFission\Obj;

/** A zero-budget discovery policy. No transport or enabling switch exists. */
class CrawlDiscovery extends Obj
{
    protected $_immutable = true;
    protected $_data = ['version' => 1, 'enabled' => false, 'request_budget' => 0, 'status' => 'disabled'];
}
