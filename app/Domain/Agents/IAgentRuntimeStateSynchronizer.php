<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentRuntimeStateSynchronizer
{
    public function synchronized(string $scope, callable $operation): mixed;
}
