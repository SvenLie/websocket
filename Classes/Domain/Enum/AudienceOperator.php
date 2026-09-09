<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Enum;

/**
 * Comparison operator used by an {@see \SvenLie\Websocket\Domain\Audience\AudienceCriterion}
 * to evaluate a client attribute against the criterion's values.
 */
enum AudienceOperator: string
{
    /**
     * Scalar equality: the client's attribute value must equal one of the
     * criterion values (e.g. level = 7, region = 79).
     */
    case Equals = 'eq';

    /**
     * Membership: the client's attribute value(s) must intersect the criterion
     * values (e.g. the user is a member of frontend group XYZ).
     */
    case In = 'in';

    /**
     * @param int|list<int> $actual the client's attribute value(s)
     * @param list<int> $expected the criterion values
     */
    public function matches(int|array $actual, array $expected): bool
    {
        // Multi-valued attributes (e.g. group membership) match when the client's
        // values intersect the expected set — regardless of operator.
        if (is_array($actual)) {
            return array_intersect($actual, $expected) !== [];
        }

        // Scalar attributes: both operators reduce to "value is one of expected".
        return in_array($actual, $expected, true);
    }
}
