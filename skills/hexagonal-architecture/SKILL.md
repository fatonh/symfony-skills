---
name: hexagonal-architecture
description: >
  Use when structuring a Symfony app with ports & adapters / clean architecture —
  keeping the domain framework-free, defining interfaces (ports) in the domain and
  implementations (adapters) in infrastructure. Use when the task mentions hexagonal,
  ports/adapters, or "keep the domain pure".
---

# Hexagonal Architecture (Ports & Adapters)

## The dependency rule

```
        ┌─────────────────────────────────────────┐
        │            Infrastructure                 │  ← Symfony, Doctrine, HTTP, Messenger
        │   (driving + driven adapters)             │
        │   ┌───────────────────────────────────┐   │
        │   │         Application               │   │  ← use cases / handlers
        │   │   ┌───────────────────────────┐   │   │
        │   │   │         Domain            │   │   │  ← entities, value objects, ports
        │   │   │   (no framework imports)  │   │   │
        │   │   └───────────────────────────┘   │   │
        │   └───────────────────────────────────┘   │
        └─────────────────────────────────────────┘
```

**Source code dependencies point inward.** The domain knows nothing about Symfony, Doctrine, or HTTP. Outer layers depend on inner-layer **interfaces**, never the reverse.

## Suggested package layout

```
src/
├── Domain/                     # pure PHP — no Symfony/Doctrine "use" statements
│   ├── Order/
│   │   ├── Order.php           # aggregate root (plain object, no #[ORM\...])
│   │   ├── OrderId.php         # value object
│   │   ├── OrderStatus.php     # enum
│   │   └── OrderRepository.php  # PORT (interface)
├── Application/                # use cases — orchestrates domain via ports
│   └── Order/
│       ├── CreateOrderHandler.php
│       └── CreateOrderCommand.php
└── Infrastructure/             # adapters — implement ports using Symfony/Doctrine
    ├── Persistence/Doctrine/
    │   ├── DoctrineOrderRepository.php   # ADAPTER implements Domain\Order\OrderRepository
    │   └── Mapping/Order.orm.xml         # ORM mapping kept OUT of the domain class
    └── Http/
        └── CreateOrderController.php     # driving adapter
```

## Ports live in the domain

A **port** is an interface the domain owns and the infrastructure implements.

```php
// ✅ src/Domain/Order/OrderRepository.php — no framework imports
namespace App\Domain\Order;

interface OrderRepository
{
    public function save(Order $order): void;

    public function ofId(OrderId $id): ?Order;
}
```

```php
// ✅ src/Infrastructure/Persistence/Doctrine/DoctrineOrderRepository.php
namespace App\Infrastructure\Persistence\Doctrine;

use App\Domain\Order\Order;
use App\Domain\Order\OrderId;
use App\Domain\Order\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }

    public function ofId(OrderId $id): ?Order
    {
        return $this->em->find(Order::class, $id->toString());
    }
}
```

Wire the interface to the implementation in `config/services.yaml`:

```yaml
services:
    App\Domain\Order\OrderRepository: '@App\Infrastructure\Persistence\Doctrine\DoctrineOrderRepository'
```

## Keeping the domain framework-free

The hard part in Symfony is that Doctrine attribute mapping (`#[ORM\Entity]`) couples the entity to the ORM. Two acceptable strategies:

1. **Pragmatic (default):** allow Doctrine attributes on the aggregate. You lose "zero framework imports" but keep one class. Fine for most teams.
2. **Strict:** keep the domain class pure and map it with **XML mapping** in `Infrastructure/Persistence/Doctrine/Mapping/`. The domain never imports Doctrine.

```php
// ✅ STRICT — pure aggregate, mapped externally via XML
namespace App\Domain\Order;

final class Order
{
    /** @var Collection<int, OrderLine>|OrderLine[] */
    private array $lines = [];

    private function __construct(
        private readonly OrderId $id,
        private OrderStatus $status,
    ) {}

    public static function place(OrderId $id): self
    {
        return new self($id, OrderStatus::Pending);   // invariant: new orders start Pending
    }
}
```

State the chosen strategy in the project README so the agent stays consistent.

## Application layer = use cases

One handler per use case. It depends only on **ports**, never on adapters.

```php
// ✅ src/Application/Order/CreateOrderHandler.php
namespace App\Application\Order;

use App\Domain\Order\Order;
use App\Domain\Order\OrderId;
use App\Domain\Order\OrderRepository;

final readonly class CreateOrderHandler
{
    public function __construct(private OrderRepository $orders) {}

    public function __invoke(CreateOrderCommand $command): OrderId
    {
        $order = Order::place(OrderId::generate());
        $this->orders->save($order);

        return $order->id();
    }
}
```

## Driving adapters

Controllers, console commands, and Messenger handlers are **driving adapters**. They translate a delivery mechanism into an application call and translate the result back out. They contain no business logic.

## Gotchas

- Agent puts `EntityManagerInterface` into the application/domain layer — depend on a repository **port** instead.
- Agent imports `Symfony\…` or `Doctrine\…` in `src/Domain/` — the domain must stay framework-free (or use the agreed pragmatic exception for ORM attributes only).
- Agent makes the application layer depend on the concrete `DoctrineOrderRepository` — depend on the interface; wire it in `services.yaml`.
- Agent returns a Symfony `Response` from a use-case handler — return a domain value (ID, DTO); the controller builds the HTTP response.
- Agent puts validation/HTTP concerns in the domain — those belong in adapters.
- Agent generates `#[ORM\Entity]` on a "pure" domain class when the project chose the strict XML-mapping strategy — check the README's stated strategy first.
