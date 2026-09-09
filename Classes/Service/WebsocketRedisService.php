<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Service;

use SvenLie\Websocket\Configuration\WebsocketConfiguration;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Enum\ChannelType;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Generic snapshot cache for websocket handlers.
 *
 * The service is intentionally agnostic of any feature domain: callers pass in
 * a fully-qualified Redis key. The key prefix and its structure are owned by the
 * concrete handler, so this package stays decoupled from feature packages. The
 * low-level Redis access is abstracted behind {@see RedisClientInterface}, so no
 * concrete Redis wrapper is referenced here.
 */
class WebsocketRedisService implements SingletonInterface
{
    private const int DEFAULT_TTL_SECONDS = 300;

    private readonly int $databaseId;

    public function __construct(
        private readonly RedisClientInterface $redisService,
        WebsocketConfiguration $configuration,
    ) {
        $this->databaseId = $configuration->getRedisDatabase();
    }

    public function getBroadcastVersion(string $redisPrefix): int
    {
        try {
            $value = $this->redisService->getEntry($this->databaseId, $this->getBroadcastVersionKey($redisPrefix));

            return is_numeric($value) ? (int)$value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<mixed>|null
     */
    public function getCachedSnapshot(string $redisPrefix, string $key, int $currentVersion): ?array
    {
        try {
            $raw = $this->redisService->getEntry($this->databaseId, $this->getCombinedSnapshotKey($redisPrefix, $key));
            if (!is_string($raw) || $raw === '') {
                return null;
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['builtAtVersion'] ?? null) !== $currentVersion) {
                return null;
            }

            $payload = $decoded['payload'] ?? null;

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $indexTokens attribute tokens ("level:7", "group:12", …)
     *                                   under which this snapshot is indexed for
     *                                   targeted invalidation
     */
    public function storeSnapshot(string $redisPrefix, string $key, int $version, array $payload, array $indexTokens = [], int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        try {
            $encoded = json_encode(
                ['builtAtVersion' => $version, 'payload' => $payload],
                JSON_THROW_ON_ERROR
            );
            $this->redisService->addEntry($this->databaseId, $this->getCombinedSnapshotKey($redisPrefix, $key), $encoded, $ttlSeconds);

            foreach ($indexTokens as $token) {
                $indexKey = $this->getIndexKey($redisPrefix, $token);
                $this->redisService->addToSet($this->databaseId, $indexKey, [$key]);
                // Index sets live at least as long as the snapshots they reference.
                $this->redisService->expire($this->databaseId, $indexKey, $ttlSeconds);
            }
        } catch (\Throwable) {
            // Caching is best-effort; failures must not break the websocket flow.
        }
    }

    public function invalidateBroadcast(string $redisPrefix): void
    {
        try {
            $this->redisService->increment(
                $this->databaseId,
                $this->getBroadcastVersionKey($redisPrefix),
            );
        } catch (\Throwable) {
            // best-effort: invalidation must never break the flow
        }
    }

    /**
     * Deletes exactly the cached snapshots whose context satisfies the audience,
     * resolved via the reverse index. Groups are AND-combined; tokens within a
     * group are OR-combined — identical to how the audience is matched.
     *
     * @param list<list<string>> $tokenGroups
     */
    public function invalidateByTokenGroups(string $redisPrefix, array $tokenGroups): void
    {
        try {
            if ($tokenGroups === []) {
                return;
            }

            $snapshotKeys = $this->resolveSnapshotKeysForTokenGroups($redisPrefix, $tokenGroups);
            if ($snapshotKeys === []) {
                return;
            }

            foreach ($snapshotKeys as $snapshotKey) {
                $this->redisService->removeEntry($this->databaseId, $this->getCombinedSnapshotKey($redisPrefix, $snapshotKey));
            }

            // Keep the queried index sets tidy (best-effort; TTL is the safety net).
            $this->dropFromIndex($redisPrefix, $tokenGroups, $snapshotKeys);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Resolves the cached snapshot keys matching the token groups: OR within a
     * group, AND across groups — mirroring {@see AudienceSpecification::matches()}.
     *
     * @param list<list<string>> $tokenGroups
     * @return list<string>
     */
    private function resolveSnapshotKeysForTokenGroups(string $redisPrefix, array $tokenGroups): array
    {
        $intersection = null;
        foreach ($tokenGroups as $tokens) {
            $union = $this->unionMembers($redisPrefix, $tokens);
            $intersection = $intersection === null ? $union : array_intersect_key($intersection, $union);
            if ($intersection === []) {
                return [];
            }
        }

        return array_map(strval(...), array_keys($intersection ?? []));
    }

    /**
     * Union of the index-set members for the given tokens, as a set (value => true).
     *
     * @param list<string> $tokens
     * @return array<string, true>
     */
    private function unionMembers(string $redisPrefix, array $tokens): array
    {
        $union = [];
        foreach ($tokens as $token) {
            foreach ($this->redisService->getSetMembers($this->databaseId, $this->getIndexKey($redisPrefix, $token)) as $member) {
                $union[$member] = true;
            }
        }

        return $union;
    }

    /**
     * @param list<list<string>> $tokenGroups
     * @param list<string> $snapshotKeys
     */
    private function dropFromIndex(string $redisPrefix, array $tokenGroups, array $snapshotKeys): void
    {
        foreach ($tokenGroups as $tokens) {
            foreach ($tokens as $token) {
                $this->redisService->removeFromSet($this->databaseId, $this->getIndexKey($redisPrefix, $token), $snapshotKeys);
            }
        }
    }

    public function publishBroadcast(string $redisPrefix): void
    {
        try {
            $this->redisService->publish(
                ChannelType::Broadcast->channelFor($redisPrefix),
                json_encode(['broadcast' => true], JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            // best-effort: publishing must never break the flow
        }
    }

    public function publishForAudience(string $redisPrefix, AudienceSpecification $specification): void
    {
        try {
            $this->redisService->publish(
                ChannelType::Unicast->channelFor($redisPrefix),
                json_encode(
                    ['audience' => $specification->toArray()],
                    JSON_THROW_ON_ERROR,
                ),
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function getCombinedSnapshotKey(string $redisPrefix, string $key): string
    {
        return sprintf('%s:snapshot:%s', $redisPrefix, $key);
    }

    private function getIndexKey(string $redisPrefix, string $token): string
    {
        return sprintf('%s:index:%s', $redisPrefix, $token);
    }

    private function getBroadcastVersionKey(string $redisPrefix): string
    {
        return sprintf('%s:broadcast:version', $redisPrefix);
    }
}
