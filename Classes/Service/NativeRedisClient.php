<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Service;

use SvenLie\Websocket\Configuration\WebsocketConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Self-contained {@see RedisClientInterface} implementation over the native
 * \Redis (ext-redis) extension.
 *
 * This is what makes svenlie_websocket plug-and-play: the extension ships its own
 * Redis client and only needs the connection configured in the Extension
 * Configuration ({@see WebsocketConfiguration}). No feature package has to
 * provide a Redis adapter binding.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final class NativeRedisClient implements RedisClientInterface, SingletonInterface
{
    private ?\Redis $redis = null;

    private ?int $selectedDb = null;

    public function __construct(
        private readonly WebsocketConfiguration $configuration,
    ) {}

    public function getEntry(int $database, string $identifier): mixed
    {
        return $this->connection($database)->get($identifier);
    }

    public function addEntry(int $database, string $identifier, string $value, int $timeToLiveInSeconds): void
    {
        $this->connection($database)->set($identifier, $value, $timeToLiveInSeconds);
    }

    public function removeEntry(int $database, string $identifier): void
    {
        $this->connection($database)->del($identifier);
    }

    public function increment(int $database, string $identifier): int
    {
        return (int)$this->connection($database)->incr($identifier);
    }

    public function addToSet(int $database, string $identifier, array $members): void
    {
        if ($members === []) {
            return;
        }

        $this->connection($database)->sAdd($identifier, ...$members);
    }

    public function removeFromSet(int $database, string $identifier, array $members): void
    {
        if ($members === []) {
            return;
        }

        $this->connection($database)->srem($identifier, ...$members);
    }

    public function getSetMembers(int $database, string $identifier): array
    {
        $members = $this->connection($database)->sMembers($identifier);

        return is_array($members) ? array_map(strval(...), $members) : [];
    }

    public function expire(int $database, string $identifier, int $timeToLiveInSeconds): void
    {
        $this->connection($database)->expire($identifier, $timeToLiveInSeconds);
    }

    public function publish(string $channel, string $message): int
    {
        // Pub/sub is global (not database-scoped); the configured database is used
        // only to establish the shared connection.
        return (int)$this->connection($this->configuration->getRedisDatabase())->publish($channel, $message);
    }

    private function connection(int $database): \Redis
    {
        if ($this->redis === null) {
            $this->redis = $this->connect();
        }

        if ($this->selectedDb !== $database) {
            if (!$this->redis->select($database)) {
                throw new \RuntimeException(sprintf('Could not select Redis database %d', $database), 1757404801);
            }
            $this->selectedDb = $database;
        }

        return $this->redis;
    }

    private function connect(): \Redis
    {
        $redis = new \Redis();
        $host = $this->configuration->getRedisHost();
        $port = $this->configuration->getRedisPort();

        if (!$redis->connect($host, $port)) {
            throw new \RuntimeException(sprintf('Redis connection to %s:%d not possible', $host, $port), 1757404802);
        }

        $password = $this->configuration->getRedisPassword();
        if ($password !== '') {
            $redis->auth(['pass' => $password]);
        }

        return $redis;
    }
}
