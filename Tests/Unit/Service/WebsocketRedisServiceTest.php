<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Configuration\WebsocketConfiguration;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;
use SvenLie\Websocket\Service\RedisClientInterface;
use SvenLie\Websocket\Service\WebsocketRedisService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(WebsocketRedisService::class)]
class WebsocketRedisServiceTest extends UnitTestCase
{
    private function configuration(int $database = 0): WebsocketConfiguration
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['redisDatabase' => $database]);

        return new WebsocketConfiguration($extensionConfiguration);
    }

    #[Test]
    public function storeSnapshotWritesPayloadAndIndexesEveryToken(): void
    {
        $entries = [];
        $sets = [];
        $expiries = [];

        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('addEntry')->willReturnCallback(
            function (int $db, string $key, string $value, int $ttl) use (&$entries): void {
                $entries[$key] = ['value' => $value, 'ttl' => $ttl];
            },
        );
        $redis->method('addToSet')->willReturnCallback(
            function (int $db, string $key, array $members) use (&$sets): void {
                $sets[$key] = $members;
            },
        );
        $redis->method('expire')->willReturnCallback(
            function (int $db, string $key, int $ttl) use (&$expiries): void {
                $expiries[$key] = $ttl;
            },
        );

        $service = new WebsocketRedisService($redis, $this->configuration());
        $service->storeSnapshot('feature', '4711:12', 5, ['entries' => []], ['level:7', 'region:79'], 300);

        self::assertArrayHasKey('feature:snapshot:4711:12', $entries);
        $decoded = json_decode($entries['feature:snapshot:4711:12']['value'], true);
        self::assertSame(5, $decoded['builtAtVersion']);
        self::assertSame(['entries' => []], $decoded['payload']);
        self::assertSame(300, $entries['feature:snapshot:4711:12']['ttl']);

        self::assertSame(['4711:12'], $sets['feature:index:level:7']);
        self::assertSame(['4711:12'], $sets['feature:index:region:79']);
        self::assertSame(300, $expiries['feature:index:level:7']);
        self::assertSame(300, $expiries['feature:index:region:79']);
    }

    #[Test]
    public function storeSnapshotIsBestEffortAndSwallowsRedisErrors(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('addEntry')->willThrowException(new \RuntimeException('down'));

        $service = new WebsocketRedisService($redis, $this->configuration());

        // Must not bubble up.
        $service->storeSnapshot('p', 'k', 1, ['entries' => []], ['level:7']);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function getCachedSnapshotReturnsPayloadWhenVersionMatches(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getEntry')->willReturn(
            json_encode(['builtAtVersion' => 5, 'payload' => ['entries' => [['id' => 'x']]]], JSON_THROW_ON_ERROR),
        );

        $service = new WebsocketRedisService($redis, $this->configuration());

        self::assertSame(['entries' => [['id' => 'x']]], $service->getCachedSnapshot('p', 'k', 5));
    }

    #[Test]
    public function getCachedSnapshotReturnsNullOnVersionMismatch(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getEntry')->willReturn(
            json_encode(['builtAtVersion' => 4, 'payload' => ['entries' => []]], JSON_THROW_ON_ERROR),
        );

        $service = new WebsocketRedisService($redis, $this->configuration());

        self::assertNull($service->getCachedSnapshot('p', 'k', 5));
    }

    #[Test]
    public function getCachedSnapshotReturnsNullForNonStringOrInvalidJson(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getEntry')->willReturnOnConsecutiveCalls(false, '', 'not-json');

        $service = new WebsocketRedisService($redis, $this->configuration());

        self::assertNull($service->getCachedSnapshot('p', 'k', 5));
        self::assertNull($service->getCachedSnapshot('p', 'k', 5));
        self::assertNull($service->getCachedSnapshot('p', 'k', 5));
    }

    #[Test]
    public function getBroadcastVersionCoercesAndDefaultsToZero(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getEntry')->willReturnOnConsecutiveCalls('7', 'nan');

        $service = new WebsocketRedisService($redis, $this->configuration());

        self::assertSame(7, $service->getBroadcastVersion('p'));
        self::assertSame(0, $service->getBroadcastVersion('p'));
    }

    #[Test]
    public function invalidateBroadcastIncrementsVersionKey(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())
            ->method('increment')
            ->with(self::anything(), 'p:broadcast:version');

        (new WebsocketRedisService($redis, $this->configuration()))->invalidateBroadcast('p');
    }

    #[Test]
    public function invalidateByTokenGroupsDeletesOnlyTheIntersection(): void
    {
        $removedKeys = [];
        $removedFromSets = [];

        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getSetMembers')->willReturnCallback(
            fn(int $db, string $key): array => match ($key) {
                'feature:index:level:7' => ['4711:12', '5:6'],
                'feature:index:region:79' => ['4711:12', '9:9'],
                default => [],
            },
        );
        $redis->method('removeEntry')->willReturnCallback(
            function (int $db, string $key) use (&$removedKeys): void {
                $removedKeys[] = $key;
            },
        );
        $redis->method('removeFromSet')->willReturnCallback(
            function (int $db, string $key, array $members) use (&$removedFromSets): void {
                $removedFromSets[$key] = $members;
            },
        );

        $service = new WebsocketRedisService($redis, $this->configuration());
        // level = 7 AND region = 79
        $service->invalidateByTokenGroups('feature', [['level:7'], ['region:79']]);

        self::assertSame(['feature:snapshot:4711:12'], $removedKeys);
        self::assertSame(['4711:12'], $removedFromSets['feature:index:level:7']);
        self::assertSame(['4711:12'], $removedFromSets['feature:index:region:79']);
    }

    #[Test]
    public function invalidateByTokenGroupsUnionsValuesWithinACriterion(): void
    {
        $removedKeys = [];

        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getSetMembers')->willReturnCallback(
            fn(int $db, string $key): array => match ($key) {
                'p:index:group:12' => ['1:1'],
                'p:index:group:34' => ['2:2'],
                default => [],
            },
        );
        $redis->method('removeEntry')->willReturnCallback(
            function (int $db, string $key) use (&$removedKeys): void {
                $removedKeys[] = $key;
            },
        );

        $service = new WebsocketRedisService($redis, $this->configuration());
        $service->invalidateByTokenGroups('p', [['group:12', 'group:34']]);

        sort($removedKeys);
        self::assertSame(['p:snapshot:1:1', 'p:snapshot:2:2'], $removedKeys);
    }

    #[Test]
    public function invalidateByTokenGroupsDoesNothingWhenIntersectionIsEmpty(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getSetMembers')->willReturnCallback(
            fn(int $db, string $key): array => match ($key) {
                'p:index:level:7' => ['1:1'],
                'p:index:region:79' => ['2:2'],
                default => [],
            },
        );
        $redis->expects(self::never())->method('removeEntry');
        $redis->expects(self::never())->method('removeFromSet');

        (new WebsocketRedisService($redis, $this->configuration()))->invalidateByTokenGroups('p', [['level:7'], ['region:79']]);
    }

    #[Test]
    public function invalidateByTokenGroupsIsBestEffort(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->method('getSetMembers')->willThrowException(new \RuntimeException('down'));
        $redis->expects(self::never())->method('removeEntry');

        (new WebsocketRedisService($redis, $this->configuration()))->invalidateByTokenGroups('p', [['level:7']]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function publishBroadcastPublishesOnBroadcastChannel(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())
            ->method('publish')
            ->with('feature.broadcast', json_encode(['broadcast' => true], JSON_THROW_ON_ERROR));

        (new WebsocketRedisService($redis, $this->configuration()))->publishBroadcast('feature');
    }

    #[Test]
    public function publishForAudiencePublishesSerialisedSpecificationOnUnicastChannel(): void
    {
        $specification = AudienceSpecification::forAttribute(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);

        $redis = $this->createMock(RedisClientInterface::class);
        $redis->expects(self::once())
            ->method('publish')
            ->with(
                'feature.unicast',
                json_encode(['audience' => $specification->toArray()], JSON_THROW_ON_ERROR),
            );

        (new WebsocketRedisService($redis, $this->configuration()))->publishForAudience('feature', $specification);
    }
}
