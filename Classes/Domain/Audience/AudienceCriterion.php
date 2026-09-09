<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Audience;

use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;

/**
 * A single targeting predicate: "<attribute> <operator> <values>",
 * e.g. level = 7, region = 79 or group IN (12, 34).
 */
final readonly class AudienceCriterion
{
    /**
     * @param list<int> $values
     */
    public function __construct(
        public AudienceAttribute $attribute,
        public AudienceOperator $operator,
        public array $values,
    ) {}

    public function matches(AudienceContext $context): bool
    {
        return $this->operator->matches($context->valueFor($this->attribute), $this->values);
    }

    /**
     * @return array{attribute: string, operator: string, values: list<int>}
     */
    public function toArray(): array
    {
        return [
            'attribute' => $this->attribute->value,
            'operator' => $this->operator->value,
            'values' => $this->values,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $attributeValue = (string)($data['attribute'] ?? '');
        $operator = AudienceOperator::tryFrom((string)($data['operator'] ?? ''));
        $rawValues = $data['values'] ?? null;

        if ($attributeValue === '' || $operator === null || !is_array($rawValues)) {
            return null;
        }

        return new self(AudienceAttribute::fromValue($attributeValue), $operator, self::normalizeValues($rawValues));
    }

    /**
     * @param array<mixed> $rawValues
     * @return list<int>
     */
    private static function normalizeValues(array $rawValues): array
    {
        $values = [];
        foreach ($rawValues as $value) {
            if (self::isIntLike($value)) {
                $values[] = (int)$value;
            }
        }

        return array_values(array_unique($values));
    }

    private static function isIntLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value));
    }
}
