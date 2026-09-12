<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentContinuationScopeStore
{
    public function get(string $token): ?array;

    public function put(string $token, array $scope): void;

    public function delete(string $token): void;
}
