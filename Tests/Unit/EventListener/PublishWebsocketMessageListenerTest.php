<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;
use SvenLie\Websocket\Event\PublishWebsocketMessageEvent;
use SvenLie\Websocket\EventListener\PublishWebsocketMessageListener;
use SvenLie\Websocket\Service\PublisherInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Fake publisher recording the payloads it was asked to publish.
 */
final class FakePublisher implements PublisherInterface
{
    /** @var list<WebsocketPayloadInterface> */
    public array $published = [];

    public function __construct(private readonly bool $supports) {}

    public function supports(WebsocketPayloadInterface $payload): bool
    {
        return $this->supports;
    }

    public function publish(WebsocketPayloadInterface $payload): void
    {
        $this->published[] = $payload;
    }
}

#[CoversClass(PublishWebsocketMessageListener::class)]
class PublishWebsocketMessageListenerTest extends UnitTestCase
{
    #[Test]
    public function dispatchesOnlyToSupportingPublishers(): void
    {
        $supporting = new FakePublisher(true);
        $notSupporting = new FakePublisher(false);

        $listener = new PublishWebsocketMessageListener([$supporting, $notSupporting]);
        $payload = $this->createMock(WebsocketPayloadInterface::class);

        $listener(new PublishWebsocketMessageEvent($payload));

        self::assertSame([$payload], $supporting->published);
        self::assertSame([], $notSupporting->published);
    }

    #[Test]
    public function dispatchesToAllSupportingPublishers(): void
    {
        $first = new FakePublisher(true);
        $second = new FakePublisher(true);

        $listener = new PublishWebsocketMessageListener([$first, $second]);
        $payload = $this->createMock(WebsocketPayloadInterface::class);

        $listener(new PublishWebsocketMessageEvent($payload));

        self::assertSame([$payload], $first->published);
        self::assertSame([$payload], $second->published);
    }

    #[Test]
    public function usesTheCurrentEventPayload(): void
    {
        $publisher = new FakePublisher(true);
        $listener = new PublishWebsocketMessageListener([$publisher]);

        $original = $this->createMock(WebsocketPayloadInterface::class);
        $replacement = $this->createMock(WebsocketPayloadInterface::class);

        $event = new PublishWebsocketMessageEvent($original);
        $event->setPayload($replacement);

        $listener($event);

        self::assertSame([$replacement], $publisher->published);
    }
}
