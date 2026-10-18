<?php

declare(strict_types=1);

namespace App\Domain\AddOn;

interface IAddOnOrganizationStore
{
    /** @return array{revision:int, groups:array, tags:array} */
    public function load(string $scope): array;

    /** Commit state and audit together, or throw without changing either. */
    public function save(string $scope, int $expectedRevision, array $state, array $audit): int;

    public function history(string $scope, int $after = 0, int $limit = 100): array;
}
