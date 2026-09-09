<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AudienceOperator::class)]
class AudienceOperatorTest extends UnitTestCase
{
    #[Test]
    public function equalsMatchesWhenScalarValueIsInExpected(): void
    {
        self::assertTrue(AudienceOperator::Equals->matches(7, [7]));
        self::assertTrue(AudienceOperator::Equals->matches(7, [5, 7, 9]));
    }

    #[Test]
    public function equalsDoesNotMatchWhenScalarValueIsMissing(): void
    {
        self::assertFalse(AudienceOperator::Equals->matches(7, [5, 9]));
    }

    #[Test]
    public function inMatchesWhenScalarValueIsInExpected(): void
    {
        self::assertTrue(AudienceOperator::In->matches(3, [1, 2, 3]));
        self::assertFalse(AudienceOperator::In->matches(4, [1, 2, 3]));
    }

    #[Test]
    public function emptyExpectedNeverMatches(): void
    {
        self::assertFalse(AudienceOperator::Equals->matches(7, []));
        self::assertFalse(AudienceOperator::In->matches(7, []));
        self::assertFalse(AudienceOperator::In->matches([7], []));
    }

    #[Test]
    public function multiValuedActualMatchesOnIntersection(): void
    {
        self::assertTrue(AudienceOperator::In->matches([5, 9], [9, 12]));
        self::assertTrue(AudienceOperator::Equals->matches([5, 9], [9, 12]));
    }

    #[Test]
    public function multiValuedActualDoesNotMatchWhenDisjoint(): void
    {
        self::assertFalse(AudienceOperator::In->matches([5, 9], [1, 2]));
    }

    #[Test]
    public function emptyMultiValuedActualNeverMatches(): void
    {
        self::assertFalse(AudienceOperator::In->matches([], [1, 2]));
    }

    #[Test]
    public function matchIsTypeStrict(): void
    {
        // '7' (string) must not match 7 (int) — values are integers.
        self::assertFalse(AudienceOperator::Equals->matches(7, [0]));
    }
}
