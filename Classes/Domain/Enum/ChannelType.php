<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Enum;

/**
 * Redis pub/sub channel suffix for a websocket handler.
 *
 * Single source of truth for channel naming: both the publisher
 * (WebsocketRedisService) and the subscriber (RunWebsocketServerCommand) derive
 * the concrete channel from a handler identifier via {@see channelFor()}.
 */
enum ChannelType: string
{
    case Broadcast = 'broadcast';
    case Unicast = 'unicast';

    /**
     * Builds the fully-qualified channel name for a handler identifier,
     * e.g. "chat" + Broadcast → "chat.broadcast".
     */
    public function channelFor(string $identifier): string
    {
        return sprintf('%s.%s', $identifier, $this->value);
    }
}
