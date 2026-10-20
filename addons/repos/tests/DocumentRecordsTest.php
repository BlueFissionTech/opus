<?php

declare(strict_types=1);

namespace AddOns\Repos\Tests;

use AddOns\Repos\Domain\Values\BlobReference;
use AddOns\Repos\Domain\Values\Document;
use AddOns\Repos\Domain\Values\Folder;
use AddOns\Repos\Domain\Values\Share;
use AddOns\Repos\Domain\Values\StoragePolicy;
use AddOns\Repos\Domain\Values\StorageResult;
use AddOns\Repos\Domain\Values\Version;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DocumentRecordsTest extends TestCase
{
    public function testTraversalAndControlCharactersCannotBecomeDocumentOrFolderIds(): void
    {
        foreach (['', '../file', '..', '/absolute', 'a\\b', "name\0", "name\n", str_repeat('a', 81)] as $id) {
            $this->rejects(fn () => new Document($id, 'report.txt', 'owner'));
            $this->rejects(fn () => new Folder($id, 'Reports', 'owner'));
            $this->rejects(fn () => new Document('doc', 'report.txt', 'owner', $id));
        }
        self::assertSame('doc-1', (new Document('doc-1', 'report.txt', 'owner_1'))->id);
    }

    public function testDisplayNamesCannotSupplyPathsOrControlCharacters(): void
    {
        foreach (['', '.', '..', '../file', 'folder/file', 'folder\\file', "name\n", "name\0", str_repeat('x', 161)] as $name) {
            $this->rejects(fn () => new Document('doc', $name, 'owner'));
        }
        self::assertSame('Résumé.txt', (new Document('doc', 'Résumé.txt', 'owner'))->name);
        $this->rejects(fn () => new Folder('folder', 'Reports', 'owner', 'folder'));
    }

    public function testIntegrityAndVersionBoundsAreStrict(): void
    {
        $blob = new BlobReference(str_repeat('a', 32), hash('sha256', ''), 0);
        self::assertSame(0, $blob->bytes);
        $this->rejects(fn () => new BlobReference('../blob', hash('sha256', ''), 0));
        $this->rejects(fn () => new BlobReference(str_repeat('a', 32), 'not-a-digest', 0));
        $this->rejects(fn () => new BlobReference(str_repeat('a', 32), hash('sha256', ''), -1));
        $this->rejects(fn () => new BlobReference(str_repeat('a', 32), hash('sha256', ''), 65537));
        $this->rejects(fn () => new Version('doc', 0, $blob, 10, 10));
        $this->rejects(fn () => new Version('doc', 1, $blob, -1, 10));
        $this->rejects(fn () => new Version('doc', 1, $blob, 10, 9));
    }

    public function testShareExpiresAtTheBoundaryAndNeverGrantsWrite(): void
    {
        $share = new Share('doc', 'reader', 100);
        self::assertTrue($share->permitsReadAt(99));
        self::assertFalse($share->permitsReadAt(100));
        self::assertFalse($share->permitsReadAt(-1));
        self::assertSame('read', $share->toArray()['permission']);
        $this->rejects(fn () => new Share('doc', 'reader', 0));
    }

    public function testPolicyCannotSilentlyRemoveSmallFileAndRetentionBounds(): void
    {
        $policy = new StoragePolicy();
        self::assertFalse($policy->allowSharing);
        self::assertSame(65536, $policy->maximumBytes);
        $this->rejects(fn () => new StoragePolicy(0));
        $this->rejects(fn () => new StoragePolicy(65537));
        $this->rejects(fn () => new StoragePolicy(retentionSeconds: -1));
        $this->rejects(fn () => new StoragePolicy(retentionSeconds: 315360001));
    }

    public function testResultCarriesReferencesRatherThanBinaryAndCannotExposeFailedMetadata(): void
    {
        $bytes = "\0private binary\xff";
        $document = new Document('doc', 'report.bin', 'owner');
        $blob = new BlobReference(str_repeat('a', 32), hash('sha256', $bytes), strlen($bytes));
        $version = new Version('doc', 1, $blob, 10, 20);
        $result = new StorageResult('completed', $document, $version, true);
        $serialized = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private binary', $serialized);
        self::assertSame($blob->toArray(), $result->toArray()['version']['blob']);
        self::assertSame('denied', (new StorageResult('denied'))->status);
        $this->rejects(fn () => new StorageResult('denied', $document));
        $this->rejects(fn () => new StorageResult('unavailable', replayed: true));
        $this->rejects(fn () => new StorageResult('completed', new Document('other', 'a.txt', 'owner'), $version));
        $this->rejects(fn () => new StorageResult('invented'));
    }

    public function testAuthorityAndIntegrityRecordsAreImmutable(): void
    {
        $document = new Document('doc', 'a.txt', 'owner');
        try {
            $document->ownerId = 'different';
            self::fail('Ownership was mutable.');
        } catch (\Error) {
            self::assertSame('owner', $document->ownerId);
        }
    }

    public function testGlobalNormalizationCannotAttestToDifferentStoredBytes(): void
    {
        $class = new \ReflectionClass(\BlueFission\DevElation::class);
        $filters = $class->getProperty('_filters');
        $active = $class->getProperty('_isActive');
        $priorFilters = $filters->getValue();
        $priorActive = $active->getValue();
        try {
            \BlueFission\DevElation::up();
            \BlueFission\DevElation::filter('_in', static fn ($value) => $value === '../unsafe' ? 'safe' : $value);
            $this->rejects(fn () => new Document('../unsafe', 'report.txt', 'owner'));
            $this->rejects(fn () => new Document('doc', '../unsafe', 'owner'));
        } finally {
            $filters->setValue(null, $priorFilters);
            $active->setValue(null, $priorActive);
        }
    }

    private function rejects(callable $factory): void
    {
        try {
            $factory();
            self::fail('Invalid record was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringStartsWith('repos_', $error->getMessage());
        }
    }
}
