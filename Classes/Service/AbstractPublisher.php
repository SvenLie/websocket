<?php

namespace SvenLie\Websocket\Service;

use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;
use SvenLie\Websocket\Handler\AbstractWebsocketClientHandler;

abstract class AbstractPublisher implements PublisherInterface
{
    abstract protected function payloadType(): string;
    abstract protected function doPublish(WebsocketPayloadInterface $payload): void;
    abstract protected function doUpdate(WebsocketPayloadInterface $payload): void;
    abstract protected function clientHandler(): AbstractWebsocketClientHandler;

    public function supports(WebsocketPayloadInterface $payload): bool
    {
        $payloadType = $this->payloadType();

        return $payload instanceof $payloadType;
    }

    public function publish(WebsocketPayloadInterface $payload): void
    {
        if (!$this->supports($payload)) {
            return;
        }

        $this->doPublish($payload);
    }
    public function update(WebsocketPayloadInterface $payload): void
    {
        if (!$this->supports($payload)) {
            return;
        }

        $this->doUpdate($payload);
    }

    /**
     * Distributes a payload's audience to connected clients via the given
     * handler: it keeps the snapshot cache consistent and then notifies the
     * relevant clients in real time (broadcast vs. targeted). This is the
     * audience contract shared by every payload, so publishers should not
     * reimplement it per payload type.
     */
    protected function dispatchToAudience(
        AbstractWebsocketClientHandler $handler,
        AudienceSpecification $audience,
    ): void {
        $identifier = $handler->getIdentifier();

        // Keep the snapshot cache consistent before connected clients rebuild:
        // the handler owns its key structure, so invalidation stays structure-agnostic.
        $handler->invalidateForAudience($audience);

        // Notify connected clients in real time via Redis pub/sub.
        if ($audience->isBroadcast()) {
            $handler->publishBroadcast($identifier);
            return;
        }

        $handler->publishForAudience($identifier, $audience);
    }

    public function delete(AudienceSpecification $audience): void
    {
        $this->dispatchToAudience($this->clientHandler(), $audience);
    }
}
