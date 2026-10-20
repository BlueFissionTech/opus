<?php

declare(strict_types=1);

namespace AddOns\Repos\Tests;

use AddOns\Repos\Domain\Values\Document;
use AddOns\Repos\Infrastructure\SQLiteDocumentStore;
use BlueFission\Connections\Database\SQLiteLink;
use BlueFission\Services\Service;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SQLiteDocumentStoreTest extends TestCase
{
    private string $path;
    private SQLiteLink $link;
    private SQLiteDocumentStore $store;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('SQLite3 is required for document storage.');
        }
        $this->path = tempnam(sys_get_temp_dir(), 'repos-');
        $this->link = new SQLiteLink(['database' => $this->path]);
        $this->link->open();
        $this->store = new SQLiteDocumentStore($this->link);
        $this->store->install();
    }

    protected function tearDown(): void
    {
        if (isset($this->link)) { $this->link->close(); }
        if (isset($this->path) && is_file($this->path)) { unlink($this->path); }
    }

    private function write(string $scope = 'team-a', string $id = 'report', string $bytes = "private\x00\xff", int $expected = 0, string $request = 'request', int $until = 200)
    {
        return $this->store->write($scope, new Document($id, 'report.bin', 'owner'), $bytes,
            hash('sha256', $bytes), $expected, $request, 100, $until);
    }

    public function testBinaryVersionsAndReceiptSurviveReopen(): void
    {
        $this->assertInstanceOf(Service::class, $this->store);
        $first = $this->write();
        $this->assertSame('completed', $first->status);
        $this->assertSame(1, $first->version->number);
        $this->assertSame(9, $first->version->blob->bytes);
        $second = $this->write(bytes: 'replacement', expected: 1, request: 'next');
        $this->assertSame(2, $second->version->number);
        $this->link->close();
        $this->link->open();
        $this->assertSame(2, $this->store->find('team-a', 'report')->version->number);
        $this->assertSame("private\x00\xff", $this->store->readBytes('team-a', 'report', 1));
        $this->assertSame('replacement', $this->store->readBytes('team-a', 'report', 2));
        $retry = $this->write();
        $this->assertTrue($retry->replayed);
        $this->assertSame($first->toArray()['version'], $retry->toArray()['version']);
        $this->assertSame('conflict', $this->write(bytes: 'different')->status);
        $this->assertSame(2, $this->store->find('team-a', 'report')->version->number);
    }

    public function testScopeOwnerPaginationAndNoBinaryMetadata(): void
    {
        $this->write(id: 'a', request: 'one');
        $this->write(id: 'b', request: 'two');
        $this->write(scope: 'team-b');
        $this->assertNull($this->store->find('team-c', 'report'));
        $this->assertSame([], $this->store->documents('team-a', 'intruder'));
        $page = $this->store->documents('team-a', 'owner', '', 1);
        $this->assertCount(1, $page);
        $this->assertSame('a', $page[0]->document->id);
        $this->assertSame('b', $this->store->documents('team-a', 'owner', 'a', 1)[0]->document->id);
        $this->assertStringNotContainsString('private', json_encode($page[0]->toArray()));
        $this->expectExceptionMessage('repos_page_invalid');
        $this->store->documents('team-a', 'owner', '', 101);
    }

    public function testVersionConflictDoesNotCreateReceiptOrOverwrite(): void
    {
        $this->write();
        $this->assertSame('conflict', $this->write(bytes: 'stale', request: 'stale')->status);
        $this->assertSame('completed', $this->write(bytes: 'fresh', expected: 1, request: 'stale')->status);
        $this->assertSame('fresh', $this->store->readBytes('team-a', 'report', 2));
        $this->assertSame('conflict', $this->store->write('team-a', new Document('report', 'report.bin', 'other'),
            'stolen', hash('sha256', 'stolen'), 2, 'steal', 100, 100)->status);
    }

    public function testReceiptFailureRollsBackBlobAndMetadataThenRetryWorks(): void
    {
        $this->link->connection()->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON repos_receipts BEGIN SELECT RAISE(ABORT,'private-database-details'); END");
        $this->assertSame('unavailable', $this->write()->status);
        $this->assertNull($this->store->find('team-a', 'report'));
        $this->assertSame(0, $this->link->connection()->querySingle('SELECT COUNT(*) FROM repos_versions'));
        $this->link->connection()->exec('DROP TRIGGER fail_receipt');
        $this->assertSame('completed', $this->write()->status);
        $this->assertTrue($this->write()->replayed);
    }

    public function testBadChecksumRejectsWriteAndTamperedStoredBytesRejectRead(): void
    {
        $this->assertSame('corrupt', $this->store->write('team-a', new Document('report', 'report.bin', 'owner'),
            'private', str_repeat('a', 64), 0, 'request', 100, 100)->status);
        $this->assertNull($this->store->find('team-a', 'report'));
        $this->write();
        $this->link->connection()->exec("UPDATE repos_versions SET bytes = X'00'");
        $this->expectExceptionMessage('repos_document_corrupt');
        $this->store->readBytes('team-a', 'report', 1);
    }

    public function testRetentionIncludesOlderVersionsAndDeletionCannotResurrectByRetry(): void
    {
        $this->write(until: 300);
        $this->write(bytes: 'new', expected: 1, request: 'next', until: 200);
        $this->assertSame('conflict', $this->store->deleteDocument('team-a', 'report', 1, 400)->status);
        $this->assertSame('retained', $this->store->deleteDocument('team-a', 'report', 2, 299)->status);
        $this->assertSame('completed', $this->store->deleteDocument('team-a', 'report', 2, 300)->status);
        $this->assertNull($this->store->find('team-a', 'report'));
        $this->assertSame([], $this->store->documents('team-a', 'owner'));
        $this->assertSame('conflict', $this->write(until: 300)->status);
        $this->assertSame('conflict', $this->write(request: 'different')->status);
        $this->expectExceptionMessage('repos_document_unavailable');
        $this->store->readBytes('team-a', 'report', 1);
    }

    public function testMigrationIsRepeatableAndRefusesDestructiveRollback(): void
    {
        $this->store->install();
        $this->store->uninstallEmpty();
        $this->store->install();
        $this->assertSame('completed', $this->write()->status);
        try {
            $this->store->uninstallEmpty();
            $this->fail('A populated store must not be dropped.');
        } catch (RuntimeException $error) {
            $this->assertSame('repos_store_not_empty', $error->getMessage());
        }
        $this->assertSame(1, $this->store->find('team-a', 'report')->version->number);
    }

    public function testActualApplicationResolvesTheInjectedLinkAndRoutesNativeAction(): void
    {
        $instances = new \ReflectionProperty(\BlueFission\Services\Application::class, '_instances');
        $before = $instances->getValue();
        try {
            $application = new \BlueFission\Services\Application(['name' => 'repos-registration']);
            $application->bindArgs(['link' => $this->link], SQLiteDocumentStore::class);
            // Resolve first so native handlers bind to the service instance.
            $application->delegate('repos.storage', $application->resolve(SQLiteDocumentStore::class));
            $registered = $application->service('repos.storage');
            $this->assertInstanceOf(SQLiteDocumentStore::class, $registered);
            $this->write();
            $observed = null;
            $application->register('repos.storage', new \BlueFission\Behavioral\Behaviors\Action('DoInspectDocument'),
                function (\BlueFission\Behavioral\Behaviors\Behavior $behavior, array $args) use (&$observed): void {
                    $observed = $this->find($args[0], $args[1]);
                });
            $application->perform('DoInspectDocument', 'team-a', 'report');
            $this->assertSame(1, $observed->version->number);
            $this->assertNotNull($registered->parent());
        } finally {
            $instances->setValue(null, $before);
        }
    }

    public function testFailedVersionWriteKeepsPriorContentAndRetryReceipt(): void
    {
        $this->write();
        $this->link->connection()->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON repos_receipts BEGIN SELECT RAISE(ABORT,'private'); END");
        $this->assertSame('unavailable', $this->write(bytes: 'new', expected: 1, request: 'next')->status);
        $this->assertSame(1, $this->store->find('team-a', 'report')->version->number);
        $this->assertSame("private\x00\xff", $this->store->readBytes('team-a', 'report', 1));
        $this->link->connection()->exec('DROP TRIGGER fail_receipt');
        $this->assertSame('completed', $this->write(bytes: 'new', expected: 1, request: 'next')->status);
    }

    public function testWrongByteCountRejectsReadEvenWithUnchangedDigest(): void
    {
        $this->write();
        $this->link->connection()->exec('UPDATE repos_versions SET byte_count = 1');
        $this->expectExceptionMessage('repos_document_corrupt');
        $this->store->readBytes('team-a', 'report', 1);
    }

    public function testConstructionAndRegistrationArePassive(): void
    {
        $this->store->uninstallEmpty();
        $newStore = new SQLiteDocumentStore($this->link);
        $factory = require dirname(__DIR__) . '/main.php';
        $registration = $factory();
        $contributions = $registration->contributions();
        $this->assertSame([], $contributions['api']);
        $this->assertSame([], $contributions['app']);
        $this->assertSame([], $contributions['default']);
        $this->assertSame([], $contributions['agents']);
        $this->assertSame([], $contributions['menus']['items']);
        $this->assertSame([], ($contributions['menus']['register'])());
        $this->assertSame(['resources' => []], $contributions['console']);
        $this->assertSame(0, $this->link->connection()->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'repos_%'"));
    }
}
