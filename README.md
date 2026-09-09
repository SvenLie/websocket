# svenlie_websocket

A generic, feature-agnostic **real-time websocket layer for TYPO3 v13**, backed by
Redis. It provides the transport, routing, audience targeting and snapshot
caching; your own extension plugs in the actual feature logic.

- **Package:** `svenlie/websocket`
- **Extension key:** `svenlie_websocket`
- **Namespace:** `SvenLie\Websocket\`

---

## What it does

- Runs a standalone websocket server (`amphp/websocket-server`) as a CLI process.
- Routes each connection by path (`/ws/<handler-identifier>`) to a handler.
- Pushes a per-client **snapshot** (`{ "type": "snapshot", "entries": [...] }`)
  on connect and whenever the data changes.
- Delivers real-time updates via **Redis pub/sub** (broadcast + targeted/unicast).
- Targets messages at an **audience** (user, groups, or arbitrary domain
  attributes) and caches snapshots per audience state.

It ships everything needed to run out of the box — you only have to configure a
Redis connection and provide a handler + an audience resolver.

---

## Requirements

- TYPO3 `^13.4`
- PHP `>= 8.4`
- `ext-redis` (the bundled Redis client)
- `amphp/websocket-server`, `amphp/redis` (pulled in via Composer)
- A reachable **Redis** server

---

## Installation

```bash
composer require svenlie/websocket
```

The extension is composer-installed and auto-activated; no `ext_emconf` toggling
needed.

---

## Step 1 — Configure Redis (the only mandatory setup)

Open **Admin Tools → Settings → Extension Configuration → `svenlie_websocket`**
and set the Redis connection:

| Field           | Meaning                                             |
| --------------- | --------------------------------------------------- |
| `redisHost`     | Host/IP. May include a scheme for the server process, e.g. `tls://redis.example.com`, `rediss://…`. |
| `redisPort`     | TCP port.                                           |
| `redisPassword` | Leave empty for no auth.                             |
| `redisDatabase` | Numeric DB index for the snapshot cache.            |

**All fields are optional.** If a field is left empty, the extension falls back
to the corresponding environment variable and then to a sensible default:

| Field           | Env fallback                 | Default     |
| --------------- | ---------------------------- | ----------- |
| `redisHost`     | `REDIS_HOST`                 | `127.0.0.1` |
| `redisPort`     | `REDIS_PORT`                 | `6379`      |
| `redisPassword` | `REDIS_PASSWORD`             | *(empty)*   |
| `redisDatabase` | `REDIS_DATABASES_WEBSOCKET`  | `0`         |

> After changing the Extension Configuration (or deploying), flush the config
> cache so the values take effect: `vendor/bin/typo3 cache:flush`.

That is all the extension itself needs. To actually push data you add the two
integration points below.

---

## Step 2 — Provide an audience-context resolver (required)

The server must know *who* is connecting. Bind
`SvenLie\Websocket\Context\AudienceContextResolverInterface` to your own
implementation that turns an incoming request into an `AudienceContext`
(user id, targetable domain attributes, frontend user group ids).

### Option A — standard fe_users authentication (recommended)

Extend `AbstractSessionAudienceContextResolver`. It performs the plain TYPO3
frontend-user check (parses the `fe_typo_user` cookie, validates the session,
and resolves the fe_user uid + fe_groups) and only asks you to add your
domain-specific attributes:

```php
namespace Vendor\MyExt\Websocket;

use Psr\Http\Message\ServerRequestInterface;
use SvenLie\Websocket\Context\AbstractSessionAudienceContextResolver;

final class MyAudienceContextResolver extends AbstractSessionAudienceContextResolver
{
    /**
     * The request carries the authenticated fe_typo_user session.
     *
     * @return array<string, int|list<int>>
     */
    protected function resolveAttributes(ServerRequestInterface $request): array
    {
        // Read your own session/domain data and map it onto attributes.
        return ['tier' => 3, 'region' => 79];
    }
}
```

### Option B — full control

Implement `AudienceContextResolverInterface` directly (e.g. for anonymous
clients or a non-fe_users auth scheme):

```php
namespace Vendor\MyExt\Websocket;

use Amp\Http\Server\Request;
use SvenLie\Websocket\Context\AudienceContextResolverInterface;
use SvenLie\Websocket\Domain\Audience\AudienceContext;

final class MyAudienceContextResolver implements AudienceContextResolverInterface
{
    public function resolve(Request $request): AudienceContext
    {
        // Authenticate the client and map its state onto the generic audience
        // model. Throw to reject the client.
        return new AudienceContext(
            userId: 4711,
            attributes: ['tier' => 3, 'region' => 79], // arbitrary domain attributes
            groupIds: [12, 34],                        // fe_groups uids
        );
    }
}
```

Register the binding in your extension's `Configuration/Services.yaml`:

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Vendor\MyExt\Websocket\MyAudienceContextResolver: ~

  SvenLie\Websocket\Context\AudienceContextResolverInterface:
    alias: Vendor\MyExt\Websocket\MyAudienceContextResolver
```

---

## Step 3 — Add a handler (required)

A handler owns one logical channel. Extend `AbstractWebsocketClientHandler` and
implement `getIdentifier()` (the channel/route name) and `buildSnapshot()` (the
data a connected client should see). Any class implementing
`RoutableWebsocketClientHandlerInterface` is auto-tagged and picked up — no
manual service config required.

```php
namespace Vendor\MyExt\Handler;

use SvenLie\Websocket\Domain\Audience\AudienceContext;
use SvenLie\Websocket\Handler\AbstractWebsocketClientHandler;

class MyClientHandler extends AbstractWebsocketClientHandler
{
    #[\Override]
    public function getIdentifier(): string
    {
        return 'myfeature'; // → route "/ws/myfeature", Redis channels "myfeature.*"
    }

    /**
     * @return array{entries: list<array<string, mixed>>}
     */
    public function buildSnapshot(AudienceContext $context): array
    {
        return ['entries' => [
            // Each entry is sent as-is to the client. An optional ISO-8601
            // "expiresAt" makes the server drop the entry and re-push on expiry.
            ['id' => 'item-1', 'title' => 'Hello', 'expiresAt' => null],
        ]];
    }

    /**
     * Optional: react to messages the client sends and re-push if needed.
     *
     * @param array<mixed> $data
     */
    #[\Override]
    protected function onClientMessage(array $data, int $clientId): void
    {
        if (($data['type'] ?? null) === 'ack') {
            // ... persist something ...
            $this->resyncClient($clientId);
        }
    }
}
```

Clients connect to `wss://<host>/ws/myfeature` and receive:

```json
{ "type": "snapshot", "entries": [ /* ... */ ] }
```

---

## Step 4 — Run the websocket server

The server is a long-running CLI process (run it under a process manager /
container, not the request lifecycle):

```bash
vendor/bin/typo3 websocketServer:run --port=8080 --interval=30
```

| Option       | Default | Meaning                                                        |
| ------------ | ------- | -------------------------------------------------------------- |
| `--port`     | `8080`  | TCP port the websocket server listens on.                      |
| `--interval` | `30`    | Reconcile poll interval in seconds (safety net; realtime is via Redis pub/sub). |

Put a TLS-terminating reverse proxy (nginx/Traefik) in front and forward
`/ws/*` to this port for `wss://`.

---

## Step 5 — Push updates (optional but usual)

To notify connected clients when data changes, dispatch a payload. Implement a
payload and a publisher; both are wired automatically.

**Payload** — carries the target `AudienceSpecification`:

```php
namespace Vendor\MyExt\Websocket;

use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;

final readonly class MyPayload implements WebsocketPayloadInterface
{
    public function __construct(private AudienceSpecification $audience) {}

    public function getAudience(): AudienceSpecification
    {
        return $this->audience;
    }
}
```

**Publisher** — extend `AbstractPublisher`, tie it to your handler:

```php
namespace Vendor\MyExt\Websocket;

use SvenLie\Websocket\Domain\Payload\WebsocketPayloadInterface;
use SvenLie\Websocket\Handler\AbstractWebsocketClientHandler;
use SvenLie\Websocket\Service\AbstractPublisher;
use Vendor\MyExt\Handler\MyClientHandler;

final class MyPublisher extends AbstractPublisher
{
    public function __construct(private readonly MyClientHandler $handler) {}

    protected function payloadType(): string { return MyPayload::class; }
    protected function clientHandler(): AbstractWebsocketClientHandler { return $this->handler; }
    protected function doPublish(WebsocketPayloadInterface $payload): void
    {
        // persist your change, then:
        $this->dispatchToAudience($this->clientHandler(), $payload->getAudience());
    }
    protected function doUpdate(WebsocketPayloadInterface $payload): void
    {
        $this->dispatchToAudience($this->clientHandler(), $payload->getAudience());
    }
}
```

**Trigger** it from anywhere via the PSR-14 event:

```php
use SvenLie\Websocket\Event\PublishWebsocketMessageEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

$dispatcher->dispatch(new PublishWebsocketMessageEvent(
    new MyPayload(AudienceSpecification::broadcast()),
));
```

### Audience targeting

```php
use SvenLie\Websocket\Domain\Audience\AudienceSpecification;
use SvenLie\Websocket\Domain\Audience\AudienceCriterion;
use SvenLie\Websocket\Domain\Enum\AudienceAttribute;
use SvenLie\Websocket\Domain\Enum\AudienceOperator;

// Everyone:
AudienceSpecification::broadcast();

// Single attribute:
AudienceSpecification::forAttribute(AudienceAttribute::scalar('region'), AudienceOperator::Equals, [79]);

// Composite (AND): tier 3 AND in group 12/34
AudienceSpecification::targeted([
    new AudienceCriterion(AudienceAttribute::scalar('tier'), AudienceOperator::Equals, [3]),
    new AudienceCriterion(AudienceAttribute::group(),        AudienceOperator::In,     [12, 34]),
]);
```

Generic attributes are `AudienceAttribute::user()` and
`AudienceAttribute::group()`; everything else is a free-form domain attribute via
`AudienceAttribute::scalar('<name>')`. The attribute names you use in a
specification must match the keys you set in the resolver's `AudienceContext`.

---

## Optional — reuse an existing Redis client

By default `SvenLie\Websocket\Service\RedisClientInterface` is bound to the
bundled `NativeRedisClient` (ext-redis). To reuse an existing Redis client,
override the alias in your `Services.yaml`:

```yaml
SvenLie\Websocket\Service\RedisClientInterface:
  alias: Vendor\MyExt\MyRedisClientAdapter   # implements RedisClientInterface
```

---

## Checklist

- [ ] `composer require svenlie/websocket`
- [ ] Configure Redis in the Extension Configuration (or set `REDIS_*` env vars)
- [ ] `vendor/bin/typo3 cache:flush`
- [ ] Bind `AudienceContextResolverInterface`
- [ ] Add a handler extending `AbstractWebsocketClientHandler`
- [ ] Start `websocketServer:run` under a process manager, proxy `/ws/*` to it
- [ ] (Optional) Add a payload + publisher and dispatch `PublishWebsocketMessageEvent`

