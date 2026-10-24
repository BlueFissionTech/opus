<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\BlueCore\Integration\Presence\PresenceBridgeFactory;
use BlueFission\Presence\Bridge\BridgeContext;
use BlueFission\Presence\Bridge\BridgeInterface;
use BlueFission\Presence\Bridge\BridgeReason;
use BlueFission\Presence\Bridge\BridgeResult;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Real installed-package contracts: never replace the bridge with a test double. */
final class NativePresenceBridgeConformanceTest extends TestCase
{
    /** @dataProvider unauthenticatedHosts */
    public function testNativeBridgeReturnsTypedDenialWithoutHostAuthentication(string $host): void
    {
        self::assertTrue(PresenceBridgeFactory::available(), 'The locked bridge contracts must be installed.');
        $bridge = PresenceBridgeFactory::make();
        self::assertInstanceOf(BridgeInterface::class, $bridge);
        $context = new BridgeContext(
            host: $host,
            tenantId: 'synthetic-tenant',
            applicationId: 'synthetic-app',
            actorId: 'unverified-actor',
            sessionId: 'unverified-session',
            metadata: ['password' => 'private-fixture-password', 'token' => 'private-fixture-token']
        );
        $before = $context->toArray();

        // IDs in an immutable context are not authentication. A missing host
        // authenticator or an unsupported host must return a native typed denial,
        // not throw, manufacture a principal, or trust context-carried authority.
        $result = $bridge->bind($context);

        self::assertInstanceOf(BridgeResult::class, $result);
        self::assertFalse($result->isBound());
        self::assertTrue(BridgeReason::isValid($result->reasonCode()));
        self::assertNotSame(BridgeReason::BOUND, $result->reasonCode());
        self::assertSame([], $result->principal());
        self::assertFalse($result->accessDecision()['allowed'] ?? false);
        self::assertSame($before, $context->toArray());
        self::assertStringNotContainsString('private-fixture', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public static function unauthenticatedHosts(): array
    {
        return [
            'supported host without authentication' => ['bluecore'],
            'unsupported host' => ['unsupported-host'],
        ];
    }

    public function testNativeContextCannotAcquireAuthorityByMutation(): void
    {
        $context = new BridgeContext(host: 'bluecore', tenantId: 'synthetic-tenant');
        $snapshot = $context->toArray();
        $snapshot['tenant_id'] = 'other-tenant';
        self::assertSame('synthetic-tenant', $context->tenantId());
        $this->expectException(LogicException::class);
        $context->tenant_id = 'other-tenant';
    }

    public function testNativeDenialEnvelopeUsesTheReleasedImmutableContract(): void
    {
        $result = new BridgeResult(
            bound: false,
            reasonCode: BridgeReason::UNAUTHORIZED,
            sessionMetadata: ['state' => 'anonymous', 'token' => 'private-fixture-token']
        );
        self::assertFalse($result->isBound());
        self::assertSame(BridgeReason::UNAUTHORIZED, $result->reasonCode());
        self::assertSame([], $result->principal());
        self::assertSame(['state' => 'anonymous'], $result->sessionMetadata());
        $this->expectException(LogicException::class);
        $result->bound = true;
    }

    public function testConformanceSnapshotIsBoundToTheExactDirectLockedGraph(): void
    {
        $root = dirname(__DIR__, 2);
        $snapshot = json_decode(file_get_contents($root . '/docs/materia-conformance.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $snapshot['schema_version']);
        self::assertSame('LF', $snapshot['composer_lock_line_endings']);
        self::assertSame(self::lockDigest(file_get_contents($root . '/composer.lock')), $snapshot['composer_lock_sha256'],
            'Dependency adoption requires fresh conformance evidence.');
        $expectedNames = array_values(array_filter(array_keys($manifest['require']),
            static fn (string $name): bool => str_starts_with($name, 'bluefission/')));
        sort($expectedNames);
        self::assertSame($expectedNames, array_column($snapshot['direct_materia_packages'], 'package'));
        $packages = array_column($lock['packages'], null, 'name');
        foreach ($snapshot['direct_materia_packages'] as $row) {
            self::assertSame($packages[$row['package']]['version'], $row['version']);
            self::assertSame($packages[$row['package']]['source']['reference'], $row['source_reference']);
        }
    }

    public function testLockDigestIgnoresCheckoutLineEndingsButNotContentChanges(): void
    {
        $lf = "{\n  \"content-hash\": \"fixture\"\n}\n";
        $crlf = "{\r\n  \"content-hash\": \"fixture\"\r\n}\r\n";
        self::assertSame(self::lockDigest($lf), self::lockDigest($crlf));
        self::assertNotSame(self::lockDigest($lf), self::lockDigest("{\n  \"content-hash\": \"changed\"\n}\n"));
    }

    private static function lockDigest(string $content): string
    {
        // Git may convert checkout line endings on Windows. Normalize only that
        // representation detail; every package/content byte remains significant.
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }
}
