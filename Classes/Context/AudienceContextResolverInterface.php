<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Context;

use Amp\Http\Server\Request;
use SvenLie\Websocket\Domain\Audience\AudienceContext;

/**
 * Resolves the {@see AudienceContext} of a connecting websocket client from the
 * incoming request (cookies, session, …).
 *
 * The concrete resolution — how a request maps to a user and the
 * domain-specific targetable attributes — is owned by the consuming application,
 * so the websocket package stays free of any authentication/session domain.
 */
interface AudienceContextResolverInterface
{
    /**
     * @throws \Throwable when the client cannot be authenticated/resolved; the
     *                    handler rejects the connection in that case.
     */
    public function resolve(Request $request): AudienceContext;
}
