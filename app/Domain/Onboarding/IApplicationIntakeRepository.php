<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

interface IApplicationIntakeRepository
{
    public function find(string $sessionId): ?ApplicationIntakeSession;

    public function save(ApplicationIntakeSession $session): ApplicationIntakeSession;
}
