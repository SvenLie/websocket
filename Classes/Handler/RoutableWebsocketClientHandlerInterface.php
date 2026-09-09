<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Handler;

use Amp\Websocket\Server\WebsocketClientHandler;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marker interface for websocket client handlers that can be routed by path.
 *
 * Any service implementing this interface is automatically tagged (as long as
 * autoconfigure is enabled) and picked up by the {@see RoutingWebsocketClientHandler}.
 * This allows feature packages to plug in their own handlers without this
 * package having to know about them.
 */
#[AutoconfigureTag(RoutableWebsocketClientHandlerInterface::TAG)]
interface RoutableWebsocketClientHandlerInterface extends WebsocketClientHandler
{
    public const string TAG = 'svenlie_websocket.client_handler';

    /**
     * The Redis pub/sub channel this handler wants to subscribe to.
     *
     * Return null if the handler does not need Redis push. Each received
     * message is dispatched back to {@see handleBroadcast()}.
     */
    public function getIdentifier(): ?string;

    /**
     * Handle a message received on the subscribed Redis channel.
     */
    public function handleBroadcast(string $payload): void;

    public function handleUnicast(string $payload): void;
}
