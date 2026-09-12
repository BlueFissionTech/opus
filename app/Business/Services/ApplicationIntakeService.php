<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Domain\Onboarding\IApplicationIntakeRepository;
use BlueFission\Arr;
use BlueFission\DevElation;
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
        $filtered = DevElation::apply(ExtensionPointCatalog::INTAKE_DEFAULTS, [
            'defaults' => ApplicationIntakeSession::defaultValues(),
            'context' => [
                'project_name' => $projectName,
                'tenant_id' => $tenantId,
                'application_slug' => $applicationSlug,
                'prompt_version' => $promptVersion,
            ],
        ]);
        $defaults = Arr::is($filtered) && Arr::is($filtered['defaults'] ?? null)
            ? Arr::toArray($filtered['defaults'], true)
            : ApplicationIntakeSession::defaultValues();

        $candidate = ApplicationIntakeSession::start(
            $projectName,
            $tenantId,
            $applicationSlug,
            $promptVersion,
            $actor,
            $correlationId,
            $defaults
        );
        $existing = $this->repository->find($candidate->sessionId());
        if (
            $existing !== null
            && $existing->answers()['project_name'] !== $candidate->answers()['project_name']
        ) {
            throw new RuntimeException('application_slug_conflict');
        }

        if ($existing !== null) {
            return $existing;
        }

        try {
            $saved = $this->repository->save($candidate);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'intake_session_already_exists') {
                throw $exception;
            }

            $existing = $this->repository->find($candidate->sessionId());
            if ($existing === null) {
                throw $exception;
            }
            if ($existing->answers()['project_name'] !== $candidate->answers()['project_name']) {
                throw new RuntimeException('application_slug_conflict');
            }

            return $existing;
        }

        $this->publishTransition('started', $saved);

        return $saved;
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
        return $this->persistTransition(
            'answered',
            $this->requireSession($sessionId)->answer($field, $value, $actor, $correlationId)
        );
    }

    public function skip(
        string $sessionId,
        string $field,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->persistTransition(
            'skipped',
            $this->requireSession($sessionId)->skip($field, $actor, $correlationId)
        );
    }

    public function pause(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->persistTransition(
            'paused',
            $this->requireSession($sessionId)->pause($actor, $correlationId)
        );
    }

    public function resume(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->persistTransition(
            'resumed',
            $this->requireSession($sessionId)->resume($actor, $correlationId)
        );
    }

    public function complete(
        string $sessionId,
        array $actor = [],
        ?string $correlationId = null
    ): ApplicationIntakeSession {
        return $this->persistTransition(
            'completed',
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

    private function persistTransition(
        string $transition,
        ApplicationIntakeSession $session
    ): ApplicationIntakeSession {
        $saved = $this->repository->save($session);

        $this->publishTransition($transition, $saved);

        return $saved;
    }

    private function publishTransition(
        string $transition,
        ApplicationIntakeSession $saved
    ): void {
        DevElation::do(ExtensionPointCatalog::INTAKE_SESSION_TRANSITIONED, [[
            'transition' => $transition,
            'session' => $saved->toArray(),
        ]]);
    }
}
