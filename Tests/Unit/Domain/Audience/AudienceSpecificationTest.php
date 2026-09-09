<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Domain\Audience\AudienceCriterion;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AudienceSpecification::class)]
class AudienceSpecificationTest extends UnitTestCase
{
    #[Test]
    public function broadcastFactoryProducesBroadcastThatMatchesEveryone(): void
    {
        $specification = AudienceSpecification::broadcast();

        self::assertTrue($specification->isBroadcast());
        self::assertTrue($specification->matches(new AudienceContext(userId: 1)));
    }

    #[Test]
    public function targetedFactoryIsNotBroadcast(): void
    {
        $specification = AudienceSpecification::targeted([
            new AudienceCriterion(AudienceAttribute::scalar('level'), AudienceOperator::Equals, [7]),
        ]);

        self::assertFalse($specification->isBroadcast());
    }

    #[Test]
    public function forAttributeWrapsASingleCriterion(): void
    {
        $specification = AudienceSpecification::forAttribute(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);

        self::assertFalse($specification->isBroadcast());
        self::assertCount(1, $specification->criteria);
        self::assertSame('region', $specification->criteria[0]->attribute->value);
    }

    #[Test]
    public function emptyNonBroadcastSpecificationMatchesNobody(): void
    {
        $specification = AudienceSpecification::targeted([]);

        self::assertFalse($specification->matches(new AudienceContext(userId: 1)));
    }

    #[Test]
    public function matchesRequiresEveryCriterion(): void
    {
        // level = 7 AND region = 79
        $specification = AudienceSpecification::targeted([
            new AudienceCriterion(AudienceAttribute::scalar('level'), AudienceOperator::Equals, [7]),
            new AudienceCriterion(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]),
        ]);

        self::assertTrue($specification->matches(new AudienceContext(userId: 1, attributes: ['level' => 7, 'region' => 79])));
        self::assertFalse($specification->matches(new AudienceContext(userId: 1, attributes: ['level' => 7, 'region' => 80])));
        self::assertFalse($specification->matches(new AudienceContext(userId: 1, attributes: ['level' => 8, 'region' => 79])));
    }

    #[Test]
    public function toArrayAndFromArrayRoundtrip(): void
    {
        $specification = AudienceSpecification::targeted([
            new AudienceCriterion(AudienceAttribute::scalar('level'), AudienceOperator::Equals, [7]),
            new AudienceCriterion(AudienceAttribute::group(), AudienceOperator::In, [12, 34]),
        ]);

        $restored = AudienceSpecification::fromArray($specification->toArray());

        self::assertEquals($specification, $restored);
    }

    #[Test]
    public function toJsonAndFromJsonRoundtrip(): void
    {
        $specification = AudienceSpecification::forAttribute(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);

        $restored = AudienceSpecification::fromJson($specification->toJson());

        self::assertEquals($specification, $restored);
    }

    #[Test]
    public function fromJsonReturnsNullForInvalidJson(): void
    {
        self::assertNull(AudienceSpecification::fromJson('{not valid json'));
    }

    #[Test]
    public function fromArrayDropsInvalidCriteria(): void
    {
        $specification = AudienceSpecification::fromArray([
            'broadcast' => false,
            'criteria' => [
                ['attribute' => 'level', 'operator' => 'eq', 'values' => [7]],
                ['attribute' => '', 'operator' => 'eq', 'values' => [1]],
                'not-an-array',
            ],
        ]);

        self::assertCount(1, $specification->criteria);
        self::assertSame('level', $specification->criteria[0]->attribute->value);
    }

    #[Test]
    public function fromArrayReadsBroadcastFlag(): void
    {
        $specification = AudienceSpecification::fromArray(['broadcast' => true, 'criteria' => []]);

        self::assertTrue($specification->isBroadcast());
    }

    #[Test]
    public function invalidationTokenGroupsMirrorCriteria(): void
    {
        $specification = AudienceSpecification::targeted([
            new AudienceCriterion(AudienceAttribute::scalar('level'), AudienceOperator::Equals, [7]),
            new AudienceCriterion(AudienceAttribute::group(), AudienceOperator::In, [12, 34]),
        ]);

        self::assertSame(
            [
                ['level:7'],
                ['group:12', 'group:34'],
            ],
            $specification->invalidationTokenGroups(),
        );
    }

    #[Test]
    public function invalidationTokenGroupsAreEmptyForBroadcast(): void
    {
        self::assertSame([], AudienceSpecification::broadcast()->invalidationTokenGroups());
    }
}
