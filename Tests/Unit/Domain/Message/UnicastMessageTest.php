<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;
use SvenLie\Websocket\Domain\Message\UnicastMessage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(UnicastMessage::class)]
class UnicastMessageTest extends UnitTestCase
{
    #[Test]
    public function fromPayloadParsesTargetedAudience(): void
    {
        $payload = json_encode([
            'audience' => [
                'broadcast' => false,
                'criteria' => [
                    ['attribute' => 'level', 'operator' => 'eq', 'values' => [7]],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $message = UnicastMessage::fromPayload($payload);

        self::assertInstanceOf(UnicastMessage::class, $message);
        self::assertTrue($message->specification->matches(new AudienceContext(userId: 1, attributes: ['level' => 7])));
        self::assertFalse($message->specification->matches(new AudienceContext(userId: 1, attributes: ['level' => 8])));
    }

    #[Test]
    public function fromPayloadRejectsBroadcastAudience(): void
    {
        // Broadcasts travel on the ".broadcast" channel, never as a unicast.
        $payload = json_encode(['audience' => ['broadcast' => true, 'criteria' => []]], JSON_THROW_ON_ERROR);

        self::assertNull(UnicastMessage::fromPayload($payload));
    }

    #[Test]
    public function fromPayloadReturnsNullForMissingAudience(): void
    {
        self::assertNull(UnicastMessage::fromPayload(json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function fromPayloadReturnsNullForNonArrayAudience(): void
    {
        self::assertNull(UnicastMessage::fromPayload(json_encode(['audience' => 'nope'], JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function fromPayloadReturnsNullForInvalidJson(): void
    {
        self::assertNull(UnicastMessage::fromPayload('{broken'));
    }

    #[Test]
    public function toPayloadArrayWrapsSerialisedSpecification(): void
    {
        $specification = AudienceSpecification::forAttribute(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);
        $message = new UnicastMessage($specification);

        self::assertSame(['audience' => $specification->toArray()], $message->toPayloadArray());
    }
}
