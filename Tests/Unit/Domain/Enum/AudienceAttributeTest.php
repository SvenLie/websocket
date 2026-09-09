<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AudienceAttribute::class)]
class AudienceAttributeTest extends UnitTestCase
{
    #[Test]
    public function onlyGroupIsMultiValued(): void
    {
        self::assertTrue(AudienceAttribute::group()->isMultiValued());

        self::assertFalse(AudienceAttribute::user()->isMultiValued());
        self::assertFalse(AudienceAttribute::scalar('level')->isMultiValued());
        self::assertFalse(AudienceAttribute::scalar('region')->isMultiValued());
    }

    #[Test]
    public function fromValueTreatsOnlyGroupAsMultiValued(): void
    {
        self::assertTrue(AudienceAttribute::fromValue('group')->isMultiValued());
        self::assertFalse(AudienceAttribute::fromValue('level')->isMultiValued());
    }

    #[Test]
    public function backingValuesAreStable(): void
    {
        // These values are part of the wire/JSON contract and the reverse-index
        // token format — they must not change silently.
        self::assertSame('user', AudienceAttribute::user()->value);
        self::assertSame('group', AudienceAttribute::group()->value);
        self::assertSame('level', AudienceAttribute::scalar('level')->value);
        self::assertSame('region', AudienceAttribute::scalar('region')->value);
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(AudienceAttribute::scalar('level')->equals(AudienceAttribute::fromValue('level')));
        self::assertFalse(AudienceAttribute::scalar('level')->equals(AudienceAttribute::scalar('region')));
    }
}
