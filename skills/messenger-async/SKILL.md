---
name: messenger-async
description: >
  Use when implementing async processing, CQRS, or event-driven flows in Symfony with the
  Messenger component — message/handler pairs, transports, routing, retries, failure queues,
  and the outbox pattern. Use when the task mentions Messenger, message bus, async, queue,
  command/query handler, or background job.
---

# Symfony Messenger (Async & CQRS)

## Message + handler pairs

A message is a plain, **immutable** DTO. A handler is a class with `#[AsMessageHandler]` and an `__invoke()` typed to the message. One handler per command.

```php
// Command message — immutable intent
final readonly class PlaceOrder
{
    /** @param array<int, array{productId: string, quantity: int}> $items */
    public function __construct(
        public string $customerEmail,
        public array $items,
    ) {}
}
```

```php
// Handler
#[AsMessageHandler]
final class PlaceOrderHandler
{
    public function __construct(private readonly OrderService $orders) {}

    public function __invoke(PlaceOrder $command): void
    {
        $this->orders->placeOrder($command);
    }
}
```

```php
// Dispatch from a controller/service
$this->bus->dispatch(new PlaceOrder($request->customerEmail, $request->items));
```

## Separate buses for command / query / event (CQRS)

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus
        buses:
            command.bus:                          # one handler, returns void/id
                middleware: [validation, doctrine_transaction]
            query.bus:                            # one handler, returns a read model
            event.bus:                            # zero-or-many handlers, fan-out
                default_middleware: allow_no_handlers
```

- **Commands**: change state, exactly one handler, named imperatively (`PlaceOrder`).
- **Queries**: return data, one handler, no side effects (`GetOrderSummary`).
- **Events**: something happened, many subscribers allowed, past tense (`OrderPlaced`).

## Transports & routing

Sync by default; route the slow/external work to an async transport.

```yaml
framework:
    messenger:
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'   # doctrine:// , amqp:// , redis://
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2                       # exponential backoff
            failed: 'doctrine://default?queue_name=failed'

        failure_transport: failed

        routing:
            App\Message\Command\PlaceOrder: async
            App\Message\Event\OrderPlaced: async
```

Run a worker to consume async messages:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
```

In production, supervise the worker (systemd / Supervisor) and recycle it (`--time-limit`) to avoid leaks.

## Retries & the failure transport

- Transient failures (network blip) → automatic retry with backoff.
- Permanent failures land in the `failed` transport instead of being lost.

```bash
php bin/console messenger:failed:show          # inspect
php bin/console messenger:failed:retry         # replay after a fix
php bin/console messenger:failed:remove <id>   # discard
```

Throw `UnrecoverableMessageHandlingException` to skip retries for errors that will never succeed (bad payload, business rule violation).

## Idempotency

Async messages can be delivered more than once (retry, at-least-once transports). Handlers must be **idempotent** — process by a natural key and no-op if already done.

```php
public function __invoke(ChargePayment $message): void
{
    if ($this->payments->existsByIdempotencyKey($message->idempotencyKey)) {
        return; // already processed — safe to receive twice
    }
    $this->gateway->charge($message);
}
```

## Outbox pattern: atomic DB write + reliable dispatch

Dispatching a message *after* `flush()` can lose it if the process dies in between; dispatching *before* can emit an event for a transaction that then rolls back. The Doctrine transport with the `DispatchAfterCurrentBusMiddleware` (built in) handles the in-bus case; for cross-process reliability, persist the message in the same transaction (outbox) and let a relay/worker publish it.

```php
// Dispatch within the handler's transaction; the transport row commits atomically.
$this->bus->dispatch(new OrderPlaced($order->getId()), [new DispatchAfterCurrentBusStamp()]);
```

## Conventions

- Messages are immutable, serializable DTOs — no entities, no closures, no services inside them.
- Pass IDs, not Doctrine entities — the entity may be stale by the time the worker runs; reload it in the handler.
- Keep handlers thin — delegate to an application service (see `layered-architecture`).
- One handler per command; events may have many.
- Name by intent: commands imperative, events past tense.

## Gotchas

- Agent puts a Doctrine entity inside a message — pass the ID and reload in the handler; entities don't serialize cleanly and go stale.
- Agent writes non-idempotent handlers — async is at-least-once; guard with an idempotency key.
- Agent never configures a `failure_transport` — failed messages vanish; add the `failed` transport.
- Agent retries permanent failures forever — throw `UnrecoverableMessageHandlingException` for non-retryable errors.
- Agent dispatches an event before `flush()` (or after, unguarded) — use `DispatchAfterCurrentBusStamp` / outbox for atomicity.
- Agent runs handlers synchronously for slow/external work — route those messages to an async transport.
- Agent forgets the worker needs supervising + memory/time limits in prod.
- Agent puts business logic in the handler — delegate to a service; the handler just adapts the message.
