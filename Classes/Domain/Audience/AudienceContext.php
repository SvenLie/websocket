<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Audience;

use SvenLie\Websocket\Domain\Enum\AudienceAttribute;

/**
 * Snapshot of a connected client's targetable attributes, captured once on
 * connect. An {@see AudienceSpecification} is evaluated against this context to
 * decide whether a push reaches the client.
 */
final readonly class AudienceContext
{
    /**
     * @param array<string, int|list<int>> $attributes domain-specific targetable
     *        attributes keyed by their attribute value (e.g. ['level' => 7, 'region' => 79])
     * @param list<int> $groupIds frontend user group uids (incl. subgroups)
     */
    public function __construct(public int $userId, private array $attributes = [], public array $groupIds = []) {}

    /**
     * Resolves the client-side value for the given attribute. Unknown attributes
     * resolve to an empty list (multi-valued) or 0 (scalar), so a stored audience
     * targeting an attribute this client does not carry simply never matches.
     *
     * @return int|list<int>
     */
    public function valueFor(AudienceAttribute $attribute): int|array
    {
        return match ($attribute->value) {
            AudienceAttribute::USER => $this->userId,
            AudienceAttribute::GROUP => $this->groupIds,
            default => $this->attributes[$attribute->value] ?? ($attribute->isMultiValued() ? [] : 0),
        };
    }

    /**
     * Two clients share a cached snapshot only
     * when they resolve to the same user *and* the same targetable state
     * (attributes + groups), since that is exactly what determines which
     * messages reach them. The attribute/group part is hashed to keep the
     * key bounded and order-independent.
     */
    public function cacheKey(): string
    {
        $discriminator = $this->attributes;
        ksort($discriminator);

        $groups = $this->groupIds;
        sort($groups);
        if ($groups !== []) {
            $discriminator[AudienceAttribute::GROUP] = $groups;
        }

        if ($discriminator === []) {
            return (string)$this->userId;
        }

        return $this->userId . ':' . substr(md5((string)json_encode($discriminator)), 0, 12);
    }

    /**
     * Tokens under which this context's snapshot is indexed in the reverse
     * index, so an attribute-based invalidation can find exactly this cached
     * snapshot ({@see cacheKey()}).
     *
     * @return list<string>
     */
    public function indexTokens(): array
    {
        $tokens = [
            AudienceAttribute::USER . ':' . $this->userId,
        ];
        foreach ($this->attributes as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $tokens[] = $name . ':' . $single;
            }
        }
        foreach ($this->groupIds as $groupId) {
            $tokens[] = AudienceAttribute::GROUP . ':' . $groupId;
        }

        return $tokens;
    }
}
