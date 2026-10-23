<?php
declare(strict_types=1);
namespace AddOns\Aimos\Domain;

use BlueFission\Automata\Goal\Initiative;

/** Narrow visibility adapter over Automata's existing owned collections. */
final class ReportingInitiative extends Initiative
{
    public function kpis(): array { return $this->collectionValues($this->_kpis); }
    public function tasks(): array { return $this->collectionValues($this->_tasks); }
}
