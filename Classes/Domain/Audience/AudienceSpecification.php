<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Audience;

use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;

/**
 * Describes the target audience of a push as a set of criteria that are
 * AND-combined and evaluated against a connected client's {@see AudienceContext}.
 *
 * Two special shapes:
 *  - broadcast: reaches every client (delivered via the ".broadcast" channel);
 *  - an empty, non-broadcast spec: reaches no one.
 *
 * Composite example ("level 7 in region 79"):
 *   new AudienceSpecification(false, [
 *       new AudienceCriterion(AudienceAttribute::scalar('level'),  AudienceOperator::Equals, [7]),
 *       new AudienceCriterion(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]),
 *   ]);
 */
final readonly class AudienceSpecification
{
    /**
     * Prefer the named constructors {@see broadcast()}, {@see targeted()} and
     * {@see forAttribute()} at call sites; the boolean here is value-object
     * state (broadcast vs. targeted), not a behaviour switch.
     *
     * @param list<AudienceCriterion> $criteria
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        public bool $broadcast = false,
        public array $criteria = [],
    ) {}

    public static function broadcast(): self
    {
        return new self(true);
    }

    /**
     * Named constructor for a targeted (non-broadcast) audience.
     *
     * @param list<AudienceCriterion> $criteria
     */
    public static function targeted(array $criteria): self
    {
        return new self(false, $criteria);
    }

    /**
     * Convenience factory for a single-attribute audience.
     *
     * @param list<int> $values
     */
    public static function forAttribute(AudienceAttribute $attribute, AudienceOperator $operator, array $values): self
    {
        return self::targeted([new AudienceCriterion($attribute, $operator, $values)]);
    }

    public function isBroadcast(): bool
    {
        return $this->broadcast;
    }

    /**
     * Whether the given client is targeted by this audience. Broadcast always
     * matches; otherwise every criterion must match (AND semantics).
     */
    public function matches(AudienceContext $context): bool
    {
        if ($this->broadcast) {
            return true;
        }

        if ($this->criteria === []) {
            return false;
        }
        return array_all($this->criteria, fn($criterion) => $criterion->matches($context));
    }

    /**
     * @return array{broadcast: bool, criteria: list<array{attribute: string, operator: string, values: list<int>}>}
     */
    public function toArray(): array
    {
        return [
            'broadcast' => $this->broadcast,
            'criteria' => array_map(static fn(AudienceCriterion $c): array => $c->toArray(), $this->criteria),
        ];
    }

    /**
     * Reverse-index tokens grouped per criterion for targeted cache
     * invalidation. Groups are AND-combined, tokens within a group are
     * OR-combined — mirroring {@see matches()} so both resolve the same clients.
     *
     * @return list<list<string>>
     */
    public function invalidationTokenGroups(): array
    {
        $groups = [];
        foreach ($this->criteria as $criterion) {
            $tokens = [];
            foreach ($criterion->values as $value) {
                $tokens[] = $criterion->attribute->value . ':' . $value;
            }
            if ($tokens !== []) {
                $groups[] = $tokens;
            }
        }

        return $groups;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $broadcast = (bool)($data['broadcast'] ?? false);

        $criteria = [];
        $rawCriteria = $data['criteria'] ?? [];
        if (is_array($rawCriteria)) {
            foreach ($rawCriteria as $rawCriterion) {
                if (!is_array($rawCriterion)) {
                    continue;
                }
                $criterion = AudienceCriterion::fromArray($rawCriterion);
                if ($criterion !== null) {
                    $criteria[] = $criterion;
                }
            }
        }

        return new self($broadcast, $criteria);
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? self::fromArray($data) : null;
    }
}
