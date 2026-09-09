<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Domain\Message;

use SvenLie\Websocket\Domain\Audience\AudienceSpecification;

/**
 * Value object describing a single unicast push instruction received on a
 * handler's ".unicast" Redis channel.
 *
 * Centralises the payload contract ({@see fromPayload()}) so both the sending
 * and receiving side can rely on the same structure. The target audience is
 * carried as a serialised {@see AudienceSpecification} (a set of AND-combined
 * criteria), which the receiving handler evaluates against each connected
 * client's context.
 */
final readonly class UnicastMessage
{
    public function __construct(
        public AudienceSpecification $specification,
    ) {}

    /**
     * Parse a raw JSON payload into a message. Returns null when the payload is
     * malformed or does not describe a targeted (non-broadcast) audience.
     */
    public static function fromPayload(string $payload): ?self
    {
        $data = json_decode($payload, true);

        if (!is_array($data) || !isset($data['audience']) || !is_array($data['audience'])) {
            return null;
        }

        $specification = AudienceSpecification::fromArray($data['audience']);

        // Broadcasts travel on the ".broadcast" channel, never as a unicast.
        if ($specification->isBroadcast()) {
            return null;
        }

        return new self($specification);
    }

    /**
     * @return array{audience: array<mixed>}
     */
    public function toPayloadArray(): array
    {
        return ['audience' => $this->specification->toArray()];
    }
}
