<?php

namespace SvenLie\Websocket\Event;

use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;

class PublishWebsocketMessageEvent
{
    public function __construct(
        private WebsocketPayloadInterface $payload,
    ) {}

    public function getPayload(): WebsocketPayloadInterface
    {
        return $this->payload;
    }

    public function setPayload(WebsocketPayloadInterface $payload): void
    {
        $this->payload = $payload;
    }
}
