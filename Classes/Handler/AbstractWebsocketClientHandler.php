<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseCode;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Revolt\EventLoop;
use SvenLie\Websocket\Context\AudienceContextResolverInterface;
use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Message\UnicastMessage;
use SvenLie\Websocket\Service\SnapshotService;
use SvenLie\Websocket\Service\WebsocketRedisService;

// TODO: Remove PHPMD suppression
/**
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.Superglobals")
 */
abstract class AbstractWebsocketClientHandler implements RoutableWebsocketClientHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AudienceContextResolverInterface $contextResolver;

    protected WebsocketRedisService $websocketRedisService;

    protected SnapshotService $snapshotService;

    private ?string $expiryTimerId = null;

    private bool $pushing = false;

    private array $clients = [];

    /**
     * Tracks the clients currently connected to this handler so broadcasts can
     * be pushed to them.
     */
    private ?WebsocketGateway $gateway = null;

    public function injectContextResolver(AudienceContextResolverInterface $contextResolver): void
    {
        $this->contextResolver = $contextResolver;
    }

    public function injectSnapshotService(SnapshotService $snapshotService): void
    {
        $this->snapshotService = $snapshotService;
    }

    public function injectWebsocketRedisService(WebsocketRedisService $websocketRedisService): void
    {
        $this->websocketRedisService = $websocketRedisService;
    }

    protected function getBroadcastVersion(string $redisPrefix): int
    {
        return $this->websocketRedisService->getBroadcastVersion($redisPrefix);
    }

    /**
     * Redis key namespace for this handler's snapshot cache.
     */
    abstract public function getIdentifier(): string;

    /**
     * Generic per-user snapshot key. The prefix and "snapshot" segment are added
     * by the WebsocketRedisService.
     */
    protected function snapshotKey(AudienceContext $context): string
    {
        return $context->cacheKey();
    }

    /**
     * Invalidates the snapshot cache affected by the given audience. Broadcasts
     * use a global version bump; targeted audiences resolve the affected cached
     * snapshots via the reverse index and delete only those.
     */
    public function invalidateForAudience(AudienceSpecification $specification): void
    {
        if ($specification->isBroadcast()) {
            $this->websocketRedisService->invalidateBroadcast($this->getIdentifier());
            return;
        }

        $tokenGroups = $specification->invalidationTokenGroups();
        if ($tokenGroups === []) {
            // A non-broadcast spec without usable criteria targets no one.
            return;
        }

        $this->websocketRedisService->invalidateByTokenGroups($this->getIdentifier(), $tokenGroups);
    }

    /**
     * A broadcast affects every connected client: rebuild and push each client's
     * own latest snapshot. Snapshot building is the shared contract of every
     * handler, so this is the sensible default. Override only if a handler needs
     * to forward a raw payload instead.
     */
    public function handleBroadcast(string $payload): void
    {
        $this->pushChanges();
    }

    public function handleUnicast(string $payload): void
    {
        $message = UnicastMessage::fromPayload($payload);

        if ($message !== null) {
            $this->pushToAudience($message->specification);
        }
    }

    public function getSnapshot(AudienceContext $context): array
    {
        return $this->snapshotService->getSnapshot(
            $this->getIdentifier(),
            $this->snapshotKey($context),
            fn(): array => $this->buildSnapshot($context),
            $context->indexTokens(),
        );
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        try {
            $context = $this->contextResolver->resolve($request);
            $this->gateway()->addClient($client);
        } catch (\Throwable $e) {
            $this->logger?->info('WS auth rejected: ' . $e->getMessage());
            // Close with a meaningful code/reason instead of the default 1000 NORMAL_CLOSURE,
            // so a rejected client can tell an auth failure apart from a normal shutdown.
            $client->close(WebsocketCloseCode::POLICY_VIOLATION, 'authentication failed');
            return;
        }

        $snapshot = $this->getSnapshot($context);

        $this->clients[$client->getId()] = [
            'client' => $client,
            'context' => $context,
            'hash' => $this->hash($snapshot),
            'nextExpiry' => $this->earliestExpiry($snapshot),
        ];
        $client->sendText(json_encode(['type' => 'snapshot', 'entries' => $snapshot['entries'] ?? null], JSON_THROW_ON_ERROR));

        $this->rescheduleExpiryTimer();

        try {
            foreach ($client as $message) {
                $data = json_decode($message->buffer(), true);
                if (is_array($data)) {
                    $this->onClientMessage($data, $client->getId());
                }
            }
        } finally {
            unset($this->clients[$client->getId()]);
            // A client left → the global "next expiry" may have moved; recompute.
            $this->rescheduleExpiryTimer();
        }
    }

    /**
     * Hook for inbound client messages. The base handler has no inbound protocol
     * of its own; feature handlers override this to react to messages a client
     * sends (e.g. acknowledgements) and call {@see resyncClient()} when the
     * client's snapshot should be re-pushed as a result.
     *
     * @param array<mixed> $data the decoded JSON message
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function onClientMessage(array $data, int $clientId): void
    {
        // no-op by default
    }

    /**
     * Force-rebuilds and re-pushes the given client's snapshot. Intended for use
     * by {@see onClientMessage()} overrides.
     */
    protected function resyncClient(int $clientId): void
    {
        $this->pushSnapshot($clientId, true);
        $this->rescheduleExpiryTimer();
    }

    protected function gateway(): WebsocketGateway
    {
        return $this->gateway ??= new WebsocketClientGateway();
    }

    private function hash(array $snapshot): string
    {
        return md5(json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function earliestExpiry(array $snapshot): ?int
    {
        $earliest = null;
        foreach ($snapshot['entries'] as $entry) {
            $expiresAt = $entry['expiresAt'] ?? null;
            if ($expiresAt === null || $expiresAt === '') {
                continue;
            }

            $timestamp = strtotime((string)$expiresAt);
            if ($timestamp === false) {
                continue;
            }

            if ($earliest === null || $timestamp < $earliest) {
                $earliest = $timestamp;
            }
        }

        return $earliest;
    }

    private function rescheduleExpiryTimer(): void
    {
        if ($this->expiryTimerId !== null) {
            EventLoop::cancel($this->expiryTimerId);
            $this->expiryTimerId = null;
        }

        $next = null;
        foreach ($this->clients as $meta) {
            $candidate = $meta['nextExpiry'] ?? null;
            if ($candidate === null) {
                continue;
            }
            if ($next === null || $candidate < $next) {
                $next = $candidate;
            }
        }

        if ($next === null) {
            return;
        }

        // Small buffer so the timer fires just *after* expiry (avoids a same-second race
        // where the entry is still considered valid).
        $delay = max(0.0, (float)($next - time()) + 0.5);

        $this->expiryTimerId = EventLoop::delay($delay, function (): void {
            $this->expiryTimerId = null;
            $this->pushChanges();
            // Defensive: pushChanges() reschedules in its finally, but if it early-returned
            // due to the reentrancy guard, make sure a timer is still armed.
            if ($this->expiryTimerId === null) {
                $this->rescheduleExpiryTimer();
            }
        });
    }

    private function pushChanges(): void
    {
        if ($this->pushing) {
            return;
        }

        $this->pushing = true;
        try {
            foreach (array_keys($this->clients) as $id) {
                $this->pushSnapshot($id, false);
            }
        } finally {
            $this->pushing = false;
            $this->rescheduleExpiryTimer();
        }
    }

    private function pushToAudience(AudienceSpecification $specification): void
    {
        foreach ($this->clients as $id => $client) {
            $context = $client['context'];
            if ($context instanceof AudienceContext && $specification->matches($context)) {
                $this->pushSnapshot($id, false);
            }
        }

        $this->rescheduleExpiryTimer();
    }

    private function pushSnapshot(int $id, bool $force): void
    {
        $entry = $this->clients[$id] ?? null;
        if ($entry === null) {
            return;
        }

        $context = $entry['context'];
        if (!$context instanceof AudienceContext) {
            return;
        }

        $snapshot = $this->getSnapshot($context);
        $hash = $this->hash($snapshot);

        // Always refresh the tracked next-expiry so the global timer stays accurate, even
        // when the snapshot content is otherwise unchanged.
        $this->clients[$id]['nextExpiry'] = $this->earliestExpiry($snapshot);

        if (!$force && $hash === $entry['hash']) {
            return;
        }

        $this->clients[$id]['hash'] = $hash;

        try {
            $entry['client']->sendText(json_encode(['type' => 'snapshot', 'entries' => $snapshot['entries']], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger?->warning('WS send failed: ' . $e->getMessage());
            unset($this->clients[$id]);
        }
    }

    public function publishBroadcast(string $redisPrefix): void
    {
        $this->websocketRedisService->publishBroadcast($redisPrefix);
    }

    public function publishForAudience(string $redisPrefix, AudienceSpecification $specification): void
    {
        $this->websocketRedisService->publishForAudience($redisPrefix, $specification);
    }

    abstract public function buildSnapshot(AudienceContext $context): array;
}
