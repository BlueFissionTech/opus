<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Repositories;

use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Domain\Onboarding\IApplicationIntakeRepository;
use App\Domain\Onboarding\Models\ApplicationIntakeModel;
use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\Val;
use RuntimeException;

final class ApplicationIntakeRepositorySql implements IApplicationIntakeRepository
{
    public function __construct(
        private ApplicationIntakeModel $model
    ) {
    }

    public function find(string $sessionId): ?ApplicationIntakeSession
    {
        $data = $this->findData($sessionId);

        return $data === null ? null : $this->hydrate($data);
    }

    public function save(ApplicationIntakeSession $session): ApplicationIntakeSession
    {
        $data = $session->toArray();
        $record = [
            'session_key' => $session->sessionId(),
            'tenant_id' => $session->tenantId(),
            'application_slug' => $session->applicationSlug(),
            'prompt_version' => $data['prompt_version'],
            'status' => $session->status(),
            'answers' => HTTP::jsonEncode($session->answers()),
            'defaults' => HTTP::jsonEncode($session->defaults()),
            'skipped' => HTTP::jsonEncode($session->skipped()),
            'actor' => HTTP::jsonEncode($data['actor']),
            'correlation_id' => $data['correlation_id'],
            'revision' => $session->revision(),
            'session_created_at' => $data['created_at'],
            'session_updated_at' => $data['updated_at'],
            'completed_at' => $data['completed_at'],
        ];

        if ($session->revision() === 1) {
            if (!$this->model->insertIfAbsent($record)) {
                throw new RuntimeException('intake_session_already_exists');
            }

            return $session;
        }

        if ($this->model->updateIfRevision($record, $session->revision() - 1)) {
            return $session;
        }

        if ($this->findData($session->sessionId()) === null && $this->model->insertIfAbsent($record)) {
            return $session;
        }

        throw new RuntimeException('intake_session_stale_revision');
    }

    private function findData(string $sessionId): ?array
    {
        $this->model->clear();
        $this->model->condition('session_key', '=', $sessionId);
        $this->model->limit(1);
        $this->model->read();
        $data = Arr::toArray($this->model->data(), true);

        return Val::isEmpty($data['session_key'] ?? null) ? null : $data;
    }

    private function hydrate(array $data): ApplicationIntakeSession
    {
        return ApplicationIntakeSession::fromArray([
            'session_id' => $data['session_key'],
            'tenant_id' => $data['tenant_id'] ?? null,
            'application_slug' => $data['application_slug'],
            'prompt_version' => $data['prompt_version'],
            'status' => $data['status'],
            'answers' => HTTP::jsonDecode((string) $data['answers'], true, []),
            'defaults' => HTTP::jsonDecode((string) $data['defaults'], true, []),
            'skipped' => HTTP::jsonDecode((string) $data['skipped'], true, []),
            'actor' => HTTP::jsonDecode((string) $data['actor'], true, []),
            'correlation_id' => $data['correlation_id'] ?? null,
            'revision' => (int) $data['revision'],
            'created_at' => $data['session_created_at'],
            'updated_at' => $data['session_updated_at'],
            'completed_at' => $data['completed_at'] ?? null,
        ]);
    }
}
