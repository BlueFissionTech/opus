<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Onboarding;

use App\Domain\Onboarding\ApplicationIntakeSession;
use App\Domain\Onboarding\Models\ApplicationIntakeModel;
use App\Domain\Onboarding\Repositories\ApplicationIntakeRepositorySql;
use BlueFission\Data\Storage\Storage;
use BlueFission\Obj;
use PHPUnit\Framework\TestCase;

final class ApplicationIntakeRepositorySqlTest extends TestCase
{
    public function testItPersistsAndHydratesAResumableSession(): void
    {
        $model = new InMemoryApplicationIntakeModel();
        $repository = new ApplicationIntakeRepositorySql($model);
        $session = ApplicationIntakeSession::start(
            'Operations Studio',
            'tenant-a',
            actor: ['id' => 'operator-a'],
            correlationId: 'start-a'
        );
        $session = $session
            ->answer('timeline', 'phased')
            ->skip('audience')
            ->complete(['id' => 'operator-a'], 'complete-a');

        $repository->save($session);
        $loaded = $repository->find($session->sessionId());

        $this->assertNotNull($loaded);
        $this->assertSame($session->toArray(), $loaded->toArray());
        $this->assertSame(1, $model->writes);

        $repository->save($loaded->resume(['id' => 'operator-b'], 'resume-b'));
        $resumed = $repository->find($session->sessionId());

        $this->assertNotNull($resumed);
        $this->assertSame(ApplicationIntakeSession::IN_PROGRESS, $resumed->status());
        $this->assertNull($resumed->toArray()['completed_at']);
        $this->assertSame('resume-b', $resumed->toArray()['correlation_id']);
        $this->assertSame(2, $model->writes);
    }
}

final class InMemoryApplicationIntakeModel extends ApplicationIntakeModel
{
    public int $writes = 0;

    private array $record = [];
    private array $working = [];
    private mixed $conditionValue = null;

    public function __construct()
    {
    }

    public function clear(): Obj
    {
        $this->working = [];

        return $this;
    }

    public function condition($field, $condition = '', $value = ''): void
    {
        $this->conditionValue = $value;
    }

    public function limit($limit): self
    {
        return $this;
    }

    public function read($values = null): Obj
    {
        $this->working = ($this->record['session_key'] ?? null) === $this->conditionValue
            ? $this->record
            : [];

        return $this;
    }

    public function data(): mixed
    {
        return $this->working;
    }

    public function assign($data): Obj
    {
        $this->working = (array) $data;

        return $this;
    }

    public function write($values = null): Obj
    {
        if ($values !== null) {
            $this->working = (array) $values;
        }

        $this->record = $this->working;
        $this->writes++;

        return $this;
    }

    public function status($message = null): mixed
    {
        return Storage::STATUS_SUCCESS;
    }
}
