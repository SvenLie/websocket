<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Service\SnapshotService;
use SvenLie\Websocket\Service\WebsocketRedisService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(SnapshotService::class)]
class SnapshotServiceTest extends UnitTestCase
{
    #[Test]
    public function returnsCachedSnapshotWithoutRebuilding(): void
    {
        $redis = $this->createMock(WebsocketRedisService::class);
        $redis->method('getBroadcastVersion')->willReturn(5);
        $redis->method('getCachedSnapshot')->with('p', 'k', 5)->willReturn(['entries' => [['id' => 'x']]]);
        $redis->expects(self::never())->method('storeSnapshot');

        $service = new SnapshotService($redis);

        $built = false;
        $result = $service->getSnapshot('p', 'k', function () use (&$built): array {
            $built = true;
            return ['entries' => []];
        });

        self::assertSame(['entries' => [['id' => 'x']]], $result);
        self::assertFalse($built, 'buildSnapshot must not run on a cache hit');
    }

    #[Test]
    public function rebuildsAndStoresWithIndexTokensOnCacheMiss(): void
    {
        $stored = null;

        $redis = $this->createMock(WebsocketRedisService::class);
        $redis->method('getBroadcastVersion')->willReturn(5);
        $redis->method('getCachedSnapshot')->willReturn(null);
        $redis->method('storeSnapshot')->willReturnCallback(
            function (string $prefix, string $key, int $version, array $payload, array $indexTokens) use (&$stored): void {
                $stored = compact('prefix', 'key', 'version', 'payload', 'indexTokens');
            },
        );

        $service = new SnapshotService($redis);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => [['id' => 42]]], ['level:7']);

        self::assertSame(['entries' => [['id' => 42]]], $result);
        self::assertSame('p', $stored['prefix']);
        self::assertSame('k', $stored['key']);
        self::assertSame(5, $stored['version']);
        // The full (unfiltered) snapshot is cached; expiry filtering happens on read.
        self::assertSame(['entries' => [['id' => 42]]], $stored['payload']);
        self::assertSame(['level:7'], $stored['indexTokens']);
    }

    #[Test]
    public function throwsWhenBuildCallbackDoesNotReturnAnArray(): void
    {
        $redis = $this->createMock(WebsocketRedisService::class);
        $redis->method('getBroadcastVersion')->willReturn(1);
        $redis->method('getCachedSnapshot')->willReturn(null);

        $service = new SnapshotService($redis);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(6910802206);

        /** @phpstan-ignore-next-line intentionally wrong return type */
        $service->getSnapshot('p', 'k', static fn(): string => 'nope');
    }

    #[Test]
    public function dropsExpiredEntriesFromCachedSnapshot(): void
    {
        $past = gmdate('c', time() - 3600);
        $future = gmdate('c', time() + 3600);

        $service = $this->serviceReturningCachedSnapshot([
            'entries' => [
                ['id' => 'keep-no-expiry'],
                ['id' => 'drop-past', 'expiresAt' => $past],
                ['id' => 'keep-future', 'expiresAt' => $future],
            ],
        ]);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => []]);

        self::assertSame(
            ['entries' => [
                ['id' => 'keep-no-expiry'],
                ['id' => 'keep-future', 'expiresAt' => $future],
            ]],
            $result,
        );
    }

    #[Test]
    public function keepsEntriesWithNullOrEmptyExpiresAt(): void
    {
        $service = $this->serviceReturningCachedSnapshot([
            'entries' => [
                ['id' => 'null-expiry', 'expiresAt' => null],
                ['id' => 'empty-expiry', 'expiresAt' => ''],
            ],
        ]);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => []]);

        self::assertCount(2, $result['entries']);
    }

    #[Test]
    public function keepsEntriesWithUnparsableExpiresAtFailingOpen(): void
    {
        $service = $this->serviceReturningCachedSnapshot([
            'entries' => [
                ['id' => 'garbage-expiry', 'expiresAt' => 'not-a-date'],
            ],
        ]);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => []]);

        self::assertSame(['entries' => [['id' => 'garbage-expiry', 'expiresAt' => 'not-a-date']]], $result);
    }

    #[Test]
    public function reindexesRemainingEntriesAsAList(): void
    {
        $past = gmdate('c', time() - 3600);

        // The expired entry sits first, so keeping key order would leave a gap.
        $service = $this->serviceReturningCachedSnapshot([
            'entries' => [
                ['id' => 'stale', 'expiresAt' => $past],
                ['id' => 'fresh'],
            ],
        ]);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => []]);

        self::assertSame(['entries' => [['id' => 'fresh']]], $result);
        self::assertArrayHasKey(0, $result['entries']);
    }

    #[Test]
    public function dropsExpiredEntriesFromRebuiltSnapshot(): void
    {
        $past = gmdate('c', time() - 3600);

        $redis = $this->createMock(WebsocketRedisService::class);
        $redis->method('getBroadcastVersion')->willReturn(1);
        $redis->method('getCachedSnapshot')->willReturn(null);

        $service = new SnapshotService($redis);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => [
            ['id' => 'fresh'],
            ['id' => 'stale', 'expiresAt' => $past],
        ]]);

        self::assertSame(['entries' => [['id' => 'fresh']]], $result);
    }

    #[Test]
    public function returnsEmptyEntriesForEmptySnapshot(): void
    {
        $service = $this->serviceReturningCachedSnapshot(['entries' => []]);

        $result = $service->getSnapshot('p', 'k', static fn(): array => ['entries' => []]);

        self::assertSame(['entries' => []], $result);
    }

    /**
     * @param array<mixed> $cached
     */
    private function serviceReturningCachedSnapshot(array $cached): SnapshotService
    {
        $redis = $this->createMock(WebsocketRedisService::class);
        $redis->method('getBroadcastVersion')->willReturn(1);
        $redis->method('getCachedSnapshot')->willReturn($cached);

        return new SnapshotService($redis);
    }
}
