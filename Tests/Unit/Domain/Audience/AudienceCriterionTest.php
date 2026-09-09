<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Domain\Audience\AudienceCriterion;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AudienceCriterion::class)]
class AudienceCriterionTest extends UnitTestCase
{
    #[Test]
    public function matchesEvaluatesAttributeAgainstContext(): void
    {
        $criterion = new AudienceCriterion(AudienceAttribute::scalar('level'), AudienceOperator::Equals, [7]);

        self::assertTrue($criterion->matches(new AudienceContext(userId: 1, attributes: ['level' => 7])));
        self::assertFalse($criterion->matches(new AudienceContext(userId: 1, attributes: ['level' => 8])));
    }

    #[Test]
    public function matchesGroupMembershipViaIn(): void
    {
        $criterion = new AudienceCriterion(AudienceAttribute::group(), AudienceOperator::In, [12, 34]);

        self::assertTrue($criterion->matches(new AudienceContext(userId: 1, groupIds: [5, 34])));
        self::assertFalse($criterion->matches(new AudienceContext(userId: 1, groupIds: [5, 9])));
    }

    #[Test]
    public function toArrayExposesWireContract(): void
    {
        $criterion = new AudienceCriterion(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);

        self::assertSame(
            ['attribute' => 'region', 'operator' => 'eq', 'values' => [79]],
            $criterion->toArray(),
        );
    }

    #[Test]
    public function fromArrayBuildsCriterionFromValidData(): void
    {
        $criterion = AudienceCriterion::fromArray([
            'attribute' => 'level',
            'operator' => 'eq',
            'values' => [7],
        ]);

        self::assertInstanceOf(AudienceCriterion::class, $criterion);
        self::assertSame('level', $criterion->attribute->value);
        self::assertSame(AudienceOperator::Equals, $criterion->operator);
        self::assertSame([7], $criterion->values);
    }

    #[Test]
    public function fromArrayCoercesNumericStringsAndDeduplicates(): void
    {
        $criterion = AudienceCriterion::fromArray([
            'attribute' => 'group',
            'operator' => 'in',
            'values' => ['12', 12, '34', 'x', ''],
        ]);

        self::assertInstanceOf(AudienceCriterion::class, $criterion);
        self::assertSame([12, 34], $criterion->values);
    }

    #[Test]
    public function fromArrayAcceptsArbitraryDomainAttribute(): void
    {
        $criterion = AudienceCriterion::fromArray([
            'attribute' => 'region',
            'operator' => 'eq',
            'values' => [1],
        ]);

        self::assertInstanceOf(AudienceCriterion::class, $criterion);
        self::assertSame('region', $criterion->attribute->value);
    }

    #[Test]
    public function fromArrayReturnsNullForEmptyAttribute(): void
    {
        self::assertNull(AudienceCriterion::fromArray([
            'attribute' => '',
            'operator' => 'eq',
            'values' => [1],
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullForUnknownOperator(): void
    {
        self::assertNull(AudienceCriterion::fromArray([
            'attribute' => 'level',
            'operator' => 'gte',
            'values' => [1],
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenValuesMissingOrNotArray(): void
    {
        self::assertNull(AudienceCriterion::fromArray(['attribute' => 'level', 'operator' => 'eq']));
        self::assertNull(AudienceCriterion::fromArray(['attribute' => 'level', 'operator' => 'eq', 'values' => 7]));
    }
}
