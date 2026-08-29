<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Domain\Onboarding\IApplicationIntakeRepository;
use RuntimeException;

final class ApplicationIntakeService
{
    public function __construct(private IApplicationIntakeRepository $repository)
    {
    }

    public function start(
        string $projectName,
        ?string $tenantId = null,
        ?string $applicationSlug = null,
        string $promptVersion = ApplicationIntakeSession::VERSION,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        $candidate = ApplicationIntakeSession::start(
            $projectName,
            $tenantId,
            $applicationSlug,
            $promptVersion,
            $actor,
            $correlationId
        );
        $existing = $this->repository->find($candidate->sessionId());
        if (
            $existing !== null
            && $existing->answers()['project_name'] !== $candidate->answers()['project_name']
        ) {
            throw new RuntimeException('application_slug_conflict');
        }

        return $existing ?? $this->repository->save($candidate);
    }

    public function find(string $sessionId): ?ApplicationIntakeSession
    {
        return $this->repository->find($sessionId);
    }

    public function answer(
        string $sessionId,
        string $field,
        mixed $value,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->repository->save(
            $this->requireSession($sessionId)->answer($field, $value, $actor, $correlationId)
        );
    }

    public function skip(
        string $sessionId,
        string $field,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->repository->save(
            $this->requireSession($sessionId)->skip($field, $actor, $correlationId)
        );
    }

    public function pause(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->repository->save(
            $this->requireSession($sessionId)->pause($actor, $correlationId)
        );
    }

    public function resume(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->repository->save(
            $this->requireSession($sessionId)->resume($actor, $correlationId)
        );
    }

    public function complete(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->repository->save(
            $this->requireSession($sessionId)->complete($actor, $correlationId)
        );
    }

    private function requireSession(string $sessionId): ApplicationIntakeSession
    {
        $session = $this->repository->find($sessionId);
        if ($session === null) {
            throw new RuntimeException('intake_session_not_found');
        }

        return $session;
    }
}
