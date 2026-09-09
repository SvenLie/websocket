<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Enum;

/**
 * Attribute of a connected client that an audience can be targeted against.
 *
 * The framework only knows the two generic attributes {@see self::user()} and
 * {@see self::group()} (mirroring standard TYPO3 fe_users / fe_groups). Any
 * domain-specific attribute (e.g. a rank or a region id) is an arbitrary
 * string value created via {@see self::scalar()} / {@see self::fromValue()} by
 * the feature package that owns that concept — the websocket package stays
 * domain-agnostic.
 *
 * Each attribute maps to a value carried by the
 * {@see \SvenLie\Websocket\Domain\Audience\AudienceContext} captured when a
 * client connects. Scalar attributes resolve to a single int; multi-valued ones
 * (e.g. {@see self::group()}) resolve to a list of ints.
 */
final readonly class AudienceAttribute
{
    /** Generic frontend user uid attribute (fe_users.uid). */
    public const string USER = 'user';

    /** Generic frontend user group attribute (standard TYPO3 fe_groups, incl. subgroups). */
    public const string GROUP = 'group';

    public function __construct(
        public string $value,
    ) {}

    public static function user(): self
    {
        return new self(self::USER);
    }

    public static function group(): self
    {
        return new self(self::GROUP);
    }

    /**
     * A single-valued, domain-specific attribute (e.g. "level", "region").
     */
    public static function scalar(string $value): self
    {
        return new self($value);
    }

    /**
     * Rebuilds an attribute from its serialised string value.
     */
    public static function fromValue(string $value): self
    {
        return new self($value);
    }

    /**
     * Whether the attribute resolves to a list of ints rather than a single int.
     * Only {@see self::GROUP} is multi-valued.
     */
    public function isMultiValued(): bool
    {
        return $this->value === self::GROUP;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
