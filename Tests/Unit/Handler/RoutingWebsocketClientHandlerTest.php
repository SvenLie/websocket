<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\WebsocketClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Handler\RoutableWebsocketClientHandlerInterface;
use SvenLie\Websocket\Handler\RoutingWebsocketClientHandler;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Minimal handler double — only getIdentifier() is relevant for the routing map;
 * the amphp-bound methods are never invoked by these tests.
 */
final readonly class FakeRoutableHandler implements RoutableWebsocketClientHandlerInterface
{
    public function __construct(private string $identifier) {}

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function handleBroadcast(string $payload): void {}

    public function handleUnicast(string $payload): void {}

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void {}
}

#[CoversClass(RoutingWebsocketClientHandler::class)]
class RoutingWebsocketClientHandlerTest extends UnitTestCase
{
    #[Test]
    public function registersEachHandlerUnderItsPrefixedRoutePath(): void
    {
        $router = new RoutingWebsocketClientHandler([
            new FakeRoutableHandler('feature'),
            new FakeRoutableHandler('chat'),
        ]);

        self::assertSame(['/ws/feature', '/ws/chat'], $router->getRegisteredPaths());
    }

    #[Test]
    public function exposesHandlersKeyedByRoutePath(): void
    {
        $feature = new FakeRoutableHandler('feature');
        $router = new RoutingWebsocketClientHandler([$feature]);

        $handlers = $router->getHandlers();

        self::assertArrayHasKey('/ws/feature', $handlers);
        self::assertSame($feature, $handlers['/ws/feature']);
    }

    #[Test]
    public function lastHandlerWinsOnDuplicateIdentifier(): void
    {
        $second = new FakeRoutableHandler('feature');
        $router = new RoutingWebsocketClientHandler([
            new FakeRoutableHandler('feature'),
            $second,
        ]);

        self::assertSame(['/ws/feature'], $router->getRegisteredPaths());
        self::assertSame($second, $router->getHandlers()['/ws/feature']);
    }
}
