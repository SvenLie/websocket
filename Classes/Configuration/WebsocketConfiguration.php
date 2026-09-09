<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Single source of truth for the extension's Redis settings.
 *
 * Values are read from the TYPO3 Extension Configuration
 * (Admin Tools → Settings → Extension Configuration → svenlie_websocket), which is
 * all that has to be configured for a plug-and-play setup. For backwards
 * compatibility each value falls back to the corresponding environment variable
 * and finally to a sensible default.
 */
final readonly class WebsocketConfiguration implements SingletonInterface
{
    private const string EXTENSION_KEY = 'svenlie_websocket';

    /**
     * @var array<string, mixed>
     */
    private array $config;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $config = [];
        try {
            $config = $extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            // Not yet installed / no configuration written → rely on env + defaults.
        }

        $this->config = is_array($config) ? $config : [];
    }

    public function getRedisHost(): string
    {
        return $this->stringValue('redisHost', 'REDIS_HOST', '127.0.0.1');
    }

    public function getRedisPort(): int
    {
        return (int)$this->stringValue('redisPort', 'REDIS_PORT', '6379');
    }

    public function getRedisPassword(): string
    {
        return $this->stringValue('redisPassword', 'REDIS_PASSWORD', '');
    }

    public function getRedisDatabase(): int
    {
        return (int)$this->stringValue('redisDatabase', 'REDIS_DATABASES_WEBSOCKET', '0');
    }

    private function stringValue(string $key, string $envKey, string $default): string
    {
        $configured = $this->config[$key] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }
        if (is_int($configured)) {
            return (string)$configured;
        }

        $env = getenv($envKey);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return $default;
    }
}
