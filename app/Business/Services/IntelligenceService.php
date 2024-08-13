<?php

namespace App\Business\Services;

use BlueFission\Services\Service;
use BlueFission\Automata\Intelligence;
use BlueFission\Automata\Language\EntityExtractor;
use BlueFission\BlueCore\Business\Managers\StrategyManager;

class IntelligenceService extends Service
{
    private $_intelligence;
    private $_strategyManager;
    private $_entityExtractor;
    private $_maxDepth;

    public function __construct(Intelligence $intelligence, StrategyManager $strategyManager, $maxDepth = 3)
    {
        $this->_intelligence = $intelligence;
        $this->_strategyManager = $strategyManager;
        $this->_entityExtractor = new EntityExtractor();
        $this->_maxDepth = $maxDepth;

        $groups = $this->_strategyManager->getDataGroups();

        foreach ($groups as $type=>$group) {
            $strategies = $group->getStrategies();
            foreach ($strategies as $strategy) {
                $this->_intelligence->registerStrategy($strategy, get_class($strategy));
            }
            $this->_intelligence->registerStrategyGroup($group);
        }

        $this->_intelligence->onPrediction(function (Behavior $event) {
            $data = $event->_context;
            echo sprintf(
                "• Strategy: %s\n  Type: %s\n  Output: %s\n\n",
                $data['strategy'],
                $data['type'],
                $data['output']
            );
        });
    }

    public function handleInput($input, int $currentDepth = 0)
    {
        if ($currentDepth >= $this->_maxDepth) {
            return;
        }

        while ( $output = $this->_intelligence->scan($input) ) {

            $this->handleInput($output, $currentDepth + 1);

            // Entity extraction and recursion
            $urls = $this->_entityExtractor->web($output);
            foreach ($urls as $url) {
                $this->handleInput($url, $currentDepth + 1);
            }
        }
    }
}
