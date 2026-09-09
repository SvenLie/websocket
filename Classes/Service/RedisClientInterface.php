<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Service;

/**
 * Minimal Redis primitive contract required by the websocket snapshot cache.
 *
 * The package stays decoupled from any concrete Redis wrapper: a consuming
 * application binds this interface to its own low-level Redis client (e.g. via a
 * thin adapter). Only the primitives actually used by {@see WebsocketRedisService}
 * are exposed here.
 */
interface RedisClientInterface
{
    public function getEntry(int $database, string $identifier): mixed;

    public function addEntry(int $database, string $identifier, string $value, int $timeToLiveInSeconds): void;

    public function removeEntry(int $database, string $identifier): void;

    public function increment(int $database, string $identifier): int;

    /**
     * @param list<string> $members
     */
    public function addToSet(int $database, string $identifier, array $members): void;

    /**
     * @param list<string> $members
     */
    public function removeFromSet(int $database, string $identifier, array $members): void;

    /**
     * @return list<string>
     */
    public function getSetMembers(int $database, string $identifier): array;

    public function expire(int $database, string $identifier, int $timeToLiveInSeconds): void;

    public function publish(string $channel, string $message): int;
}
