<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent\Orchestration\Pattern\HierarchicalPattern;

/**
 * Preserve unknown confidence in the bridge's normalized runtime results.
 */
class AgentDelegationPattern extends HierarchicalPattern
{
    protected function averageConfidence(array $workerResults): ?float
    {
        foreach ($workerResults as $result) {
            if (!Arr::hasKey($result, 'confidence') || $result['confidence'] === null) {
                return null;
            }
        }

        return parent::averageConfidence($workerResults);
    }
}
