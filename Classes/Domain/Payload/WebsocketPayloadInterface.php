<?php

namespace SvenLie\Websocket\Domain\Payload;

use SvenLie\Websocket\Domain\Audience\AudienceSpecification;

interface WebsocketPayloadInterface
{
    /**
     * The target audience that determines which connected clients receive
     * this payload. Every websocket payload must define an audience.
     */
    public function getAudience(): AudienceSpecification;
}
