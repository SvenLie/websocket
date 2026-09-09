<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Enum\ChannelType;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ChannelType::class)]
class ChannelTypeTest extends UnitTestCase
{
    #[Test]
    public function channelForAppendsSuffixToIdentifier(): void
    {
        self::assertSame('feature.broadcast', ChannelType::Broadcast->channelFor('feature'));
        self::assertSame('feature.unicast', ChannelType::Unicast->channelFor('feature'));
    }

    #[Test]
    public function backingValuesAreStable(): void
    {
        self::assertSame('broadcast', ChannelType::Broadcast->value);
        self::assertSame('unicast', ChannelType::Unicast->value);
    }
}
