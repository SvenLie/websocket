<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Dispatches an incoming websocket client to the matching {@see RoutableWebsocketClientHandlerInterface}
 * based on the request path.
 *
 * The concrete handlers are injected as a tagged iterator, so svenlie_websocket
 * stays decoupled from any feature package that provides a handler.
 */
final class RoutingWebsocketClientHandler implements WebsocketClientHandler, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string, RoutableWebsocketClientHandlerInterface>
     */
    private array $handlers = [];

    // @todo: make configurable
    private const string PREFIX = '/ws';

    /**
     * @param iterable<RoutableWebsocketClientHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[sprintf('%s/%s', self::PREFIX, $handler->getIdentifier())] = $handler;
        }
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        $path = $request->getUri()->getPath();
        $handler = $this->handlers[$path] ?? null;

        if ($handler === null) {
            $this->logger?->info('No websocket handler registered for path: ' . $path, ['SvenLieWebsocket']);
            $client->close();
            return;
        }

        $handler->handleClient($client, $request, $response);
    }

    /**
     * @return list<string>
     */
    public function getRegisteredPaths(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * All registered handlers, keyed by their (prefixed) route path.
     *
     * @return array<string, RoutableWebsocketClientHandlerInterface>
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }
}
