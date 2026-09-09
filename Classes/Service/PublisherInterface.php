<?php

namespace SvenLie\Websocket\Service;

use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(PublisherInterface::TAG)]
interface PublisherInterface
{
    public const string TAG = 'svenlie_websocket.publisher';

    public function supports(WebsocketPayloadInterface $payload): bool;
    public function publish(WebsocketPayloadInterface $payload): void;
}
