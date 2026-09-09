<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Service;

final readonly class SnapshotService
{
    public function __construct(
        private WebsocketRedisService $websocketRedisService
    ) {}

    /**
     * @param list<string> $indexTokens reverse-index tokens for the built snapshot
     */
    public function getSnapshot(string $redisPrefix, string $key, callable $buildSnapshot, array $indexTokens = []): array
    {
        $version = $this->websocketRedisService->getBroadcastVersion($redisPrefix);
        $cachedSnapshot = $this->websocketRedisService->getCachedSnapshot($redisPrefix, $key, $version);

        if ($cachedSnapshot !== null) {
            return $this->dropExpired($cachedSnapshot);
        }

        $snapshot = $buildSnapshot();

        if (!is_array($snapshot)) {
            throw new \RuntimeException('buildSnapshot callable should return an array', 6910802206);
        }

        $this->websocketRedisService->storeSnapshot($redisPrefix, $key, $version, $snapshot, $indexTokens);

        return $this->dropExpired($snapshot);
    }

    private function dropExpired(array $snapshot): array
    {
        $now = time();

        $entries = array_filter(
            $snapshot['entries'] ?? [],
            static function (array $entry) use ($now): bool {
                $expiresAt = $entry['expiresAt'] ?? null;
                if ($expiresAt === null || $expiresAt === '') {
                    return true; // never expires
                }

                $timestamp = strtotime((string)$expiresAt);

                // Keep on parse failure (fail open) and while still in the future.
                return $timestamp === false || $timestamp > $now;
            },
        );

        return ['entries' => array_values($entries)];
    }
}
