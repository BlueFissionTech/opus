<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use BlueFission\Arr;
use BlueFission\Date;
use BlueFission\Security\Hash;
use BlueFission\Str;
use BlueFission\Val;
use InvalidArgumentException;

final class ApplicationIntakeSession
{
    public const VERSION = '1.0';
    public const IN_PROGRESS = 'in_progress';
    public const PAUSED = 'paused';
    public const COMPLETED = 'completed';

    private const FIELDS = [
        'project_name',
        'project_type',
        'industry',
        'audience',
        'description',
        'specification',
        'personality',
        'agent_name',
        'delivery_strategy',
        'timeline',
    ];

    private Str $sessionId;
    private ?Str $tenantId;
    private Str $applicationSlug;
    private Str $promptVersion;
    private Str $status;
    private Arr $answers;
    private Arr $defaults;
    private Arr $skipped;
    private Arr $actor;
    private ?Str $correlationId;
    private int $revision;
    private Str $createdAt;
    private Str $updatedAt;
    private ?Str $completedAt;

    public function __construct(
        string $sessionId,
        ?string $tenantId,
        string $applicationSlug,
        string $promptVersion,
        string $status,
        array $answers,
        array $defaults,
        array $skipped,
        array $actor,
        ?string $correlationId,
        int $revision,
        string $createdAt,
        string $updatedAt,
        ?string $completedAt = null
    ) {
        $this->sessionId = Str::make($sessionId);
        $this->tenantId = Str::isNotEmpty((string) $tenantId) ? Str::make((string) $tenantId) : null;
        $this->applicationSlug = Str::make($applicationSlug);
        $this->promptVersion = Str::make($promptVersion);
        $this->status = Str::make($status);
        $this->answers = Arr::make($answers);
        $this->defaults = Arr::make($defaults);
        $this->skipped = Arr::make($skipped)->unique();
        $this->actor = Arr::make($actor);
        $this->correlationId = Str::isNotEmpty((string) $correlationId)
            ? Str::make((string) $correlationId)
            : null;
        $this->revision = $revision;
        $this->createdAt = Str::make($createdAt);
        $this->updatedAt = Str::make($updatedAt);
        $this->completedAt = Str::isNotEmpty((string) $completedAt)
            ? Str::make((string) $completedAt)
            : null;

        $this->guard();
    }

    public static function start(
        string $projectName,
        ?string $tenantId = null,
        ?string $applicationSlug = null,
        string $promptVersion = self::VERSION,
        array $actor = [],
        ?string $correlationId = null
    ): self {
        $projectName = Str::make($projectName)->trim()->val();
        if (Str::isEmpty($projectName)) {
            throw new InvalidArgumentException('project_name_required');
        }

        $slug = self::normalizeSlug($applicationSlug ?? $projectName);
        $scope = Str::isNotEmpty((string) $tenantId)
            ? 'tenant:' . (string) $tenantId
            : 'scope:application';
        $sessionId = (new Hash('sha256'))->hash('opus-intake:' . $scope . ':' . $slug);
        $now = self::now();

        return new self(
            $sessionId,
            $tenantId,
            $slug,
            $promptVersion,
            self::IN_PROGRESS,
            ['project_name' => $projectName],
            self::defaultAnswers(),
            [],
            $actor,
            $correlationId,
            1,
            $now,
            $now
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['session_id'] ?? ''),
            $data['tenant_id'] ?? null,
            (string) ($data['application_slug'] ?? ''),
            (string) ($data['prompt_version'] ?? self::VERSION),
            (string) ($data['status'] ?? self::IN_PROGRESS),
            Arr::toArray($data['answers'] ?? [], true),
            Arr::toArray($data['defaults'] ?? self::defaultAnswers(), true),
            Arr::toArray($data['skipped'] ?? [], true),
            Arr::toArray($data['actor'] ?? [], true),
            $data['correlation_id'] ?? null,
            (int) ($data['revision'] ?? 1),
            (string) ($data['created_at'] ?? self::now()),
            (string) ($data['updated_at'] ?? self::now()),
            $data['completed_at'] ?? null
        );
    }

    public function answer(string $field, mixed $value, array $actor = [], ?string $correlationId = null): self
    {
        $this->guardField($field);
        if ($field === 'project_name' && Val::isEmpty($value)) {
            throw new InvalidArgumentException('project_name_required');
        }

        $answers = $this->answers->toArray();
        $answers[$field] = $value;
        $skipped = $this->skipped
            ->filter(static fn (mixed $item): bool => $item !== $field)
            ->toArray();

        return $this->revise(
            answers: $answers,
            skipped: $skipped,
            actor: $actor,
            correlationId: $correlationId
        );
    }

    public function skip(string $field, array $actor = [], ?string $correlationId = null): self
    {
        $this->guardField($field);
        if ($field === 'project_name') {
            throw new InvalidArgumentException('project_name_required');
        }

        $answers = $this->answers
            ->filter(static fn (mixed $value, mixed $key): bool => $key !== $field)
            ->toArray();
        $skipped = Arr::make(Arr::merge($this->skipped->toArray(), [$field]))
            ->unique()
            ->toArray();

        return $this->revise(
            answers: $answers,
            skipped: $skipped,
            actor: $actor,
            correlationId: $correlationId
        );
    }

    public function pause(array $actor = [], ?string $correlationId = null): self
    {
        return $this->revise(status: self::PAUSED, actor: $actor, correlationId: $correlationId);
    }

    public function resume(array $actor = [], ?string $correlationId = null): self
    {
        return $this->revise(
            status: self::IN_PROGRESS,
            actor: $actor,
            correlationId: $correlationId,
            completedAt: null
        );
    }

    public function complete(array $actor = [], ?string $correlationId = null): self
    {
        return $this->revise(
            status: self::COMPLETED,
            actor: $actor,
            correlationId: $correlationId,
            completedAt: self::now()
        );
    }

    public function sessionId(): string
    {
        return $this->sessionId->val();
    }

    public function tenantId(): ?string
    {
        return $this->tenantId?->val();
    }

    public function applicationSlug(): string
    {
        return $this->applicationSlug->val();
    }

    public function status(): string
    {
        return $this->status->val();
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function answers(): array
    {
        return $this->answers->toArray();
    }

    public function defaults(): array
    {
        return $this->defaults->toArray();
    }

    public function skipped(): array
    {
        return $this->skipped->toArray();
    }

    public function resolvedAnswers(): array
    {
        return Arr::merge($this->defaults->toArray(), $this->answers->toArray());
    }

    public function unanswered(): array
    {
        $answered = Arr::make($this->answers->keys());
        $skipped = $this->skipped;

        return Arr::make(self::FIELDS)
            ->filter(static fn (string $field): bool => !$answered->contains($field) && !$skipped->contains($field))
            ->toArray();
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId(),
            'tenant_id' => $this->tenantId(),
            'application_slug' => $this->applicationSlug(),
            'prompt_version' => $this->promptVersion->val(),
            'status' => $this->status(),
            'answers' => $this->answers(),
            'defaults' => $this->defaults(),
            'skipped' => $this->skipped(),
            'unanswered' => $this->unanswered(),
            'resolved_answers' => $this->resolvedAnswers(),
            'actor' => $this->actor->toArray(),
            'correlation_id' => $this->correlationId?->val(),
            'revision' => $this->revision(),
            'created_at' => $this->createdAt->val(),
            'updated_at' => $this->updatedAt->val(),
            'completed_at' => $this->completedAt?->val(),
        ];
    }

    private static function defaultAnswers(): array
    {
        return [
            'project_type' => 'general_web_application',
            'industry' => 'technology_and_communications',
            'audience' => 'b2c_consumers',
            'description' => 'A general platform for professional services and automation through agentic workflows.',
            'personality' => 'professional_and_informative',
            'agent_name' => 'Opus',
        ];
    }

    private static function normalizeSlug(string $value): string
    {
        $slug = Str::make($value)
            ->trim()
            ->replace('-', '_')
            ->snake()
            ->replace('_', '-')
            ->val();

        if (Str::isEmpty($slug)) {
            throw new InvalidArgumentException('application_slug_required');
        }

        return $slug;
    }

    private static function now(): string
    {
        return Date::now()->format('Y-m-d H:i:s')->val();
    }

    private function guard(): void
    {
        if (Str::isEmpty($this->sessionId->val()) || Str::isEmpty($this->applicationSlug->val())) {
            throw new InvalidArgumentException('invalid_intake_identity');
        }
        if (!Arr::make([self::IN_PROGRESS, self::PAUSED, self::COMPLETED])->contains($this->status())) {
            throw new InvalidArgumentException('invalid_intake_status');
        }
        if (Val::isEmpty($this->answers->toArray()['project_name'] ?? null)) {
            throw new InvalidArgumentException('project_name_required');
        }
    }

    private function guardField(string $field): void
    {
        if (!Arr::make(self::FIELDS)->contains($field)) {
            throw new InvalidArgumentException('unknown_intake_field');
        }
    }

    private function revise(
        ?string $status = null,
        ?array $answers = null,
        ?array $skipped = null,
        array $actor = [],
        ?string $correlationId = null,
        ?string $completedAt = null
    ): self {
        $nextCompletedAt = $this->completedAt?->val();
        if ($status === self::COMPLETED) {
            $nextCompletedAt = $completedAt ?? self::now();
        } elseif ($status !== null) {
            $nextCompletedAt = null;
        }

        return new self(
            $this->sessionId(),
            $this->tenantId(),
            $this->applicationSlug(),
            $this->promptVersion->val(),
            $status ?? $this->status(),
            $answers ?? $this->answers(),
            $this->defaults(),
            $skipped ?? $this->skipped(),
            Arr::isNotEmpty($actor) ? $actor : $this->actor->toArray(),
            Str::isNotEmpty((string) $correlationId) ? $correlationId : $this->correlationId?->val(),
            $this->revision + 1,
            $this->createdAt->val(),
            self::now(),
            $nextCompletedAt
        );
    }
}
