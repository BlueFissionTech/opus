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

    public function testItRejectsAStaleRevisionWithoutOverwritingTheWinner(): void
    {
        $model = new InMemoryApplicationIntakeModel();
        $repository = new ApplicationIntakeRepositorySql($model);
        $session = ApplicationIntakeSession::start('Operations Studio', 'tenant-a');
        $repository->save($session);

        $first = $repository->find($session->sessionId());
        $second = $repository->find($session->sessionId());
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $repository->save($first->answer('timeline', 'discovery'));

        try {
            $repository->save($second->answer('timeline', 'production'));
            $this->fail('A stale revision must not overwrite the committed answer.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('intake_session_stale_revision', $exception->getMessage());
        }

        $stored = $repository->find($session->sessionId());
        $this->assertNotNull($stored);
        $this->assertSame('discovery', $stored->answers()['timeline']);
        $this->assertSame(2, $stored->revision());
    }

    public function testModelMutationsUseAtomicInsertAndRevisionGuards(): void
    {
        $storage = new RecordingMutationStorage([1, 0]);
        $model = new RecordingApplicationIntakeModel($storage);
        $record = [
            'session_key' => 'session-a',
            'status' => ApplicationIntakeSession::IN_PROGRESS,
            'revision' => 5,
        ];

        $this->assertTrue($model->insertIfAbsent($record));
        $this->assertFalse($model->updateIfRevision($record, 4));
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $storage->queries[0]);
        $this->assertSame('SELECT ROW_COUNT() AS affected_rows', $storage->queries[1]);
        $this->assertStringContainsString('AND `revision` = 4', $storage->queries[2]);
        $this->assertSame('SELECT ROW_COUNT() AS affected_rows', $storage->queries[3]);
    }
}

final class RecordingApplicationIntakeModel extends ApplicationIntakeModel
{
    public function __construct(RecordingMutationStorage $storage)
    {
        $this->_dataObject = $storage;
    }
}

final class RecordingMutationStorage
{
    /** @var list<string> */
    public array $queries = [];

    private array $affectedRows;
    private array $contents = [];

    public function __construct(array $affectedRows)
    {
        $this->affectedRows = $affectedRows;
    }

    public function run(string $query): self
    {
        $this->queries[] = $query;
        if ($query === 'SELECT ROW_COUNT() AS affected_rows') {
            $this->contents = ['affected_rows' => array_shift($this->affectedRows)];
        }

        return $this;
    }

    public function contents(): array
    {
        return $this->contents;
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

    public function insertIfAbsent(array $record): bool
    {
        if ($this->record !== []) {
            return false;
        }

        $this->record = $record;
        $this->writes++;

        return true;
    }

    public function updateIfRevision(array $record, int $expectedRevision): bool
    {
        if ((int) ($this->record['revision'] ?? 0) !== $expectedRevision) {
            return false;
        }

        $this->record = array_merge($this->record, $record);
        $this->writes++;

        return true;
    }

    public function status($message = null): mixed
    {
        return Storage::STATUS_SUCCESS;
    }
}
