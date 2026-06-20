---
name: domain-driven-design
description: >
  Use when modeling a domain in Symfony — aggregates, entities, value objects, domain
  events, repositories, and the ubiquitous language. Use when the task mentions DDD,
  aggregate, value object, domain event, or invariant.
---

# Domain-Driven Design (Symfony)

## Building blocks

| Tactical pattern | In Symfony/PHP |
|---|---|
| Aggregate root | Entity class that guards invariants and is the only entry point to its cluster |
| Entity | Has identity (`OrderId`), mutable over its lifecycle |
| Value object | `final readonly class`, no identity, compared by value (`Money`, `Email`) |
| Domain event | `final readonly class` recorded on the aggregate, dispatched after persist |
| Repository | Interface in the domain, Doctrine adapter in infrastructure (see `hexagonal-architecture`) |
| Domain service | Stateless logic that doesn't belong to a single aggregate |

## Value objects

Immutable, self-validating, compared by value. Replace primitive obsession.

```php
// ✅ GOOD — value object enforces its own invariants
final readonly class Email
{
    public function __construct(public string $value)
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email: {$value}");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

final readonly class Money
{
    public function __construct(public int $amountInCents, public Currency $currency) {}

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException('Currency mismatch');
        }
        return new self($this->amountInCents + $other->amountInCents, $this->currency);
    }
}

// ❌ BAD — primitives everywhere, no invariants, validation scattered across services
function createOrder(string $email, int $totalCents): void { /* ... */ }
```

Map value objects with Doctrine **embeddables** (`#[ORM\Embedded]`) or a **custom DBAL type**, so the table stays flat while the model stays rich.

## Aggregates protect invariants

- The aggregate root is the **only** object outside code may hold a reference to.
- Mutations go through **behavior methods** on the root, never setters.
- Invariants are checked inside those methods — an aggregate can never be in an invalid state.
- Keep aggregates **small**; reference other aggregates by **ID**, not object reference.

```php
// ✅ GOOD — behavior methods guard invariants; children reached only through the root
#[ORM\Entity]
class Order
{
    #[ORM\Id, ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status;

    /** @var Collection<int, OrderLine> */
    #[ORM\OneToMany(targetEntity: OrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private function __construct(Uuid $id)
    {
        $this->id = $id;
        $this->status = OrderStatus::Pending;
        $this->lines = new ArrayCollection();
    }

    public static function place(): self
    {
        $order = new self(Uuid::v7());
        $order->record(new OrderPlaced($order->id));
        return $order;
    }

    public function addLine(Uuid $productId, int $quantity): void
    {
        if ($this->status !== OrderStatus::Pending) {
            throw new \DomainException('Cannot modify a confirmed order'); // invariant
        }
        $this->lines->add(new OrderLine($this, $productId, $quantity));
    }

    public function confirm(): void
    {
        if ($this->lines->isEmpty()) {
            throw new \DomainException('Cannot confirm an empty order'); // invariant
        }
        $this->status = OrderStatus::Confirmed;
        $this->record(new OrderConfirmed($this->id));
    }

    /** @return DomainEvent[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    private function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}

// ❌ BAD — anemic entity: public setters let any caller break invariants
class Order
{
    public OrderStatus $status;
    public Collection $lines;
    public function setStatus(OrderStatus $s): void { $this->status = $s; }
}
```

## Domain events

Record events inside the aggregate; dispatch them **after** the transaction commits so subscribers never see uncommitted state. A Doctrine `postFlush` listener or Messenger does the dispatch.

```php
// ✅ Dispatch recorded events after flush via a Doctrine event subscriber
#[AsDoctrineListener(event: Events::postFlush)]
final class DispatchDomainEventsListener
{
    public function __construct(private readonly MessageBusInterface $eventBus) {}

    public function postFlush(PostFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof RecordsEvents) {
                    foreach ($entity->releaseEvents() as $event) {
                        $this->eventBus->dispatch($event);
                    }
                }
            }
        }
    }
}
```

## Ubiquitous language

- Name classes and methods with the **business** vocabulary: `Order::confirm()`, not `Order::setStatusToConfirmed()`.
- The same term means the same thing in code, tests, and conversation.
- Bounded contexts get their own namespace (`App\Sales\…`, `App\Billing\…`); the same word may differ between them.

## Gotchas

- Agent generates anemic entities with public setters — model behavior methods that enforce invariants.
- Agent uses primitive `string`/`int` for `Email`, `Money`, `OrderId` — introduce value objects.
- Agent references other aggregates by object (`#[ORM\ManyToOne] private Customer $customer`) — reference by ID across aggregate boundaries.
- Agent dispatches domain events before `flush()` — record on the aggregate, dispatch after commit.
- Agent puts cross-aggregate logic on an entity — use a stateless domain service.
- Agent makes huge aggregates loading dozens of children — keep them small; consider a separate read model.
- Agent names methods after database operations (`save`, `update`) instead of intent (`confirm`, `cancel`).
