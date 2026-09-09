<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Tests\Unit\Domain\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AudienceContext::class)]
class AudienceContextTest extends UnitTestCase
{
    #[Test]
    public function valueForResolvesScalarAttributes(): void
    {
        $context = new AudienceContext(
            userId: 4711,
            attributes: [
                'level' => 7,
                'region' => 79,
                'category' => 4,
                'segment' => 2,
            ],
            groupIds: [5, 9],
        );

        self::assertSame(4711, $context->valueFor(AudienceAttribute::user()));
        self::assertSame(7, $context->valueFor(AudienceAttribute::scalar('level')));
        self::assertSame(79, $context->valueFor(AudienceAttribute::scalar('region')));
        self::assertSame(4, $context->valueFor(AudienceAttribute::scalar('category')));
        self::assertSame(2, $context->valueFor(AudienceAttribute::scalar('segment')));
    }

    #[Test]
    public function valueForUnknownScalarAttributeDefaultsToZero(): void
    {
        $context = new AudienceContext(userId: 1);

        self::assertSame(0, $context->valueFor(AudienceAttribute::scalar('level')));
    }

    #[Test]
    public function valueForResolvesGroupsAsList(): void
    {
        $context = new AudienceContext(userId: 1, groupIds: [5, 9]);

        self::assertSame([5, 9], $context->valueFor(AudienceAttribute::group()));
    }

    #[Test]
    public function cacheKeyIsTheUserIdWhenNoDomainState(): void
    {
        $context = new AudienceContext(userId: 4711);

        self::assertSame('4711', $context->cacheKey());
    }

    #[Test]
    public function cacheKeyIsStableAndIndependentOfAttributeOrder(): void
    {
        $a = new AudienceContext(userId: 4711, attributes: ['level' => 7, 'region' => 79], groupIds: [9, 5]);
        $b = new AudienceContext(userId: 4711, attributes: ['region' => 79, 'level' => 7], groupIds: [5, 9]);

        self::assertSame($a->cacheKey(), $b->cacheKey());
        self::assertStringStartsWith('4711:', $a->cacheKey());
    }

    #[Test]
    public function cacheKeyDiffersForDifferentDomainState(): void
    {
        $a = new AudienceContext(userId: 4711, attributes: ['level' => 7]);
        $b = new AudienceContext(userId: 4711, attributes: ['level' => 8]);

        self::assertNotSame($a->cacheKey(), $b->cacheKey());
    }

    #[Test]
    public function indexTokensContainEveryAttributeValue(): void
    {
        $context = new AudienceContext(
            userId: 4711,
            attributes: [
                'level' => 7,
                'region' => 79,
                'category' => 4,
                'segment' => 2,
            ],
            groupIds: [5, 9],
        );

        self::assertSame(
            [
                'user:4711',
                'level:7',
                'region:79',
                'category:4',
                'segment:2',
                'group:5',
                'group:9',
            ],
            $context->indexTokens(),
        );
    }

    #[Test]
    public function indexTokensWithoutDomainAttributesOnlyIndexUser(): void
    {
        $context = new AudienceContext(userId: 1);

        self::assertSame(
            ['user:1'],
            $context->indexTokens(),
        );
    }

    #[Test]
    public function defaultsAreEmpty(): void
    {
        $context = new AudienceContext(userId: 1);

        self::assertSame([], $context->groupIds);
        self::assertSame([], $context->valueFor(AudienceAttribute::group()));
    }
}
