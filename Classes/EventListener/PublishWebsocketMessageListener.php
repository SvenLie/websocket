<?php

namespace SvenLie\Websocket\EventListener;

use SvenLie\Websocket\Event\PublishWebsocketMessageEvent;

final readonly class PublishWebsocketMessageListener
{
    public function __construct(
        private iterable $publishers,
    ) {}

    public function __invoke(PublishWebsocketMessageEvent $event): void
    {
        $payload = $event->getPayload();

        foreach ($this->publishers as $publisher) {
            if ($publisher->supports($payload)) {
                $publisher->publish($payload);
            }
        }
    }
}
