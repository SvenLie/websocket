<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;
use SvenLie\Websocket\Handler\AbstractWebsocketClientHandler;
use SvenLie\Websocket\Service\AbstractPublisher;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SupportedTestPayload implements WebsocketPayloadInterface
{
    public function getAudience(): AudienceSpecification
    {
        return AudienceSpecification::broadcast();
    }
}

final class UnsupportedTestPayload implements WebsocketPayloadInterface
{
    public function getAudience(): AudienceSpecification
    {
        return AudienceSpecification::broadcast();
    }
}

/**
 * Concrete test double exposing which payloads were handed to doPublish()/doUpdate().
 */
final class RecordingTestPublisher extends AbstractPublisher
{
    /** @var list<WebsocketPayloadInterface> */
    public array $published = [];

    /** @var list<WebsocketPayloadInterface> */
    public array $updated = [];

    public function __construct(
        private readonly AbstractWebsocketClientHandler $handler,
    ) {}

    protected function payloadType(): string
    {
        return SupportedTestPayload::class;
    }

    protected function doPublish(WebsocketPayloadInterface $payload): void
    {
        $this->published[] = $payload;
    }

    protected function doUpdate(WebsocketPayloadInterface $payload): void
    {
        $this->updated[] = $payload;
    }

    protected function clientHandler(): AbstractWebsocketClientHandler
    {
        return $this->handler;
    }
}

#[CoversClass(AbstractPublisher::class)]
class AbstractPublisherTest extends UnitTestCase
{
    private function createHandler(): AbstractWebsocketClientHandler
    {
        return self::createStub(AbstractWebsocketClientHandler::class);
    }

    #[Test]
    public function supportsOnlyTheDeclaredPayloadType(): void
    {
        $publisher = new RecordingTestPublisher($this->createHandler());

        self::assertTrue($publisher->supports(new SupportedTestPayload()));
        self::assertFalse($publisher->supports(new UnsupportedTestPayload()));
    }

    #[Test]
    public function publishDelegatesToDoPublishForSupportedPayload(): void
    {
        $publisher = new RecordingTestPublisher($this->createHandler());
        $payload = new SupportedTestPayload();

        $publisher->publish($payload);

        self::assertSame([$payload], $publisher->published);
    }

    #[Test]
    public function publishIgnoresUnsupportedPayload(): void
    {
        $publisher = new RecordingTestPublisher($this->createHandler());

        $publisher->publish(new UnsupportedTestPayload());

        self::assertSame([], $publisher->published);
    }

    #[Test]
    public function updateDelegatesToDoUpdateForSupportedPayload(): void
    {
        $publisher = new RecordingTestPublisher($this->createHandler());
        $payload = new SupportedTestPayload();

        $publisher->update($payload);

        self::assertSame([$payload], $publisher->updated);
    }

    #[Test]
    public function updateIgnoresUnsupportedPayload(): void
    {
        $publisher = new RecordingTestPublisher($this->createHandler());

        $publisher->update(new UnsupportedTestPayload());

        self::assertSame([], $publisher->updated);
    }
}
