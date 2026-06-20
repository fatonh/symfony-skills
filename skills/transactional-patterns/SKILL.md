---
name: transactional-patterns
description: >
  Use when managing Doctrine transaction boundaries, flush strategy, optimistic/pessimistic
  locking, or ensuring consistency across multiple writes in Symfony. Use when the task
  mentions transaction, flush, locking, concurrency, race condition, or unit of work.
---

# Transactional Patterns (Doctrine + Symfony)

## One flush per request/use case

Doctrine's Unit of Work batches changes; **`flush()` is the commit boundary**. Call it **once**, at the end of the use case, in the **service layer** — not after every `persist()`, and never in controllers, repositories, or entities.

```php
// ✅ GOOD — service owns the boundary, single flush
public function placeOrder(CreateOrderRequest $request): Order
{
    $order = Order::create($request->customerEmail);
    foreach ($request->items as $line) {
        $order->addItem(Uuid::fromString($line->productId), $line->quantity);
    }
    $this->inventory->reserve($order);    // also just persists, doesn't flush

    $this->em->persist($order);
    $this->em->flush();                   // one commit for the whole use case

    return $order;
}

// ❌ BAD — flush in a loop: N transactions, partial writes on failure
foreach ($lines as $line) {
    $em->persist($line);
    $em->flush();                          // commits each line separately
}
```

## Explicit transactions for multi-step consistency

When several operations must succeed or fail together (and a single `flush()` isn't enough — e.g. raw DBAL + ORM, or you need to react before commit), wrap them:

```php
$this->em->wrapInTransaction(function () use ($order, $payment): void {
    $this->em->persist($order);
    $this->em->persist($payment);
    // any exception in here rolls everything back
});
```

`wrapInTransaction()` (Doctrine ORM 3) begins, flushes, commits, and rolls back on exception. Prefer it over manual `beginTransaction()`/`commit()`/`rollback()` so you can't forget the rollback path.

## Optimistic locking (the default for web apps)

Two users edit the same record; the second overwrites the first ("lost update"). Guard with a `#[ORM\Version]` column.

```php
#[ORM\Version]
#[ORM\Column(type: Types::INTEGER)]
private int $version = 1;
```

On flush, if the version in the DB changed since you loaded the entity, Doctrine throws `OptimisticLockException`. Map it to `409 Conflict`.

```php
try {
    $this->em->flush();
} catch (OptimisticLockException) {
    throw new ConcurrentModificationException($order->getId()); // -> 409
}
```

This needs **no DB locks** and scales — preferred for typical request/response flows.

## Pessimistic locking (only for true contention)

For short critical sections with real contention (inventory decrement, seat booking), lock the row at read time inside a transaction:

```php
$this->em->wrapInTransaction(function () use ($id): void {
    $product = $this->em->find(
        Product::class,
        $id,
        LockMode::PESSIMISTIC_WRITE,   // SELECT ... FOR UPDATE
    );
    $product->decrementStock(1);       // no one else can read-for-update until commit
});
```

Hold the lock for the shortest possible time; long-held row locks cause contention and deadlocks. Don't use pessimistic locking as a default — it serializes access.

## `flush()` only what you mean to

- A `flush()` persists **every** managed entity with pending changes, not just the one you pass. Don't rely on `flush($entity)` to scope it (removed in ORM 3).
- Avoid triggering `flush()` inside Doctrine lifecycle callbacks or event listeners mid-request — it reorders the UoW and causes subtle bugs. Defer side effects to `postFlush`/after commit (see domain events in `domain-driven-design`).

## Cross-service consistency: outbox, not distributed transactions

When a write must also notify another system, **don't** open a transaction across HTTP/queue boundaries. Persist an outbox row in the same transaction, then dispatch asynchronously (see `messenger-async`). This keeps the DB write atomic and the side effect eventually consistent.

## Gotchas

- Agent calls `flush()` inside a loop — flush once after all `persist()` calls.
- Agent puts `flush()` / transaction logic in a controller, repository, or entity — own it in the service.
- Agent uses manual `beginTransaction()/commit()/rollback()` and forgets the rollback path — use `wrapInTransaction()`.
- Agent ignores concurrent edits — add `#[ORM\Version]` and map `OptimisticLockException` to `409`.
- Agent reaches for pessimistic `FOR UPDATE` locks by default — use optimistic locking unless there's real contention.
- Agent relies on `flush($entity)` to scope the commit — ORM 3 flushes the whole UoW.
- Agent opens a transaction spanning an external HTTP/queue call — use the outbox pattern + async dispatch.
- Agent calls `flush()` inside a Doctrine lifecycle listener — defer to `postFlush`/after commit.
