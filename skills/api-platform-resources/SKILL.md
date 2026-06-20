---
name: api-platform-resources
description: >
  Use when exposing data through API Platform 4 — defining #[ApiResource], operations,
  DTO resources, state providers/processors, serialization groups, filters, and pagination.
  Use when the task mentions API Platform, ApiResource, state processor, or exposing an
  entity as a REST/GraphQL endpoint.
---

# API Platform 4 Resources

## Prefer DTO resources over exposing entities

Exposing a Doctrine entity directly as `#[ApiResource]` couples your public API to your schema and leaks persistence concerns. For anything beyond a trivial CRUD prototype, expose a **DTO resource** backed by a **state provider** (read) and **state processor** (write).

```php
// ✅ GOOD — a resource DTO, decoupled from the entity
#[ApiResource(
    shortName: 'Order',
    operations: [
        new Get(provider: OrderItemProvider::class),
        new GetCollection(provider: OrderCollectionProvider::class),
        new Post(processor: CreateOrderProcessor::class, input: CreateOrderInput::class),
    ],
    normalizationContext: ['groups' => ['order:read']],
)]
final class OrderResource
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        #[Groups(['order:read'])]
        public string $id,

        #[Groups(['order:read'])]
        public string $status,

        /** @var LineItemResource[] */
        #[Groups(['order:read'])]
        public array $items = [],
    ) {}
}
```

```php
// ❌ BAD — Doctrine entity as the API resource: schema is now your public contract
#[ApiResource]
#[ORM\Entity]
class Order { /* every column auto-exposed, lazy associations serialized, N+1 */ }
```

If you *do* expose entities (small internal apps), always scope fields with serialization groups and disable the operations you don't want.

## State processors (write side)

A processor receives the input DTO and performs the use case — usually by delegating to an application service / Messenger bus.

```php
// ✅ GOOD
/** @implements ProcessorInterface<CreateOrderInput, OrderResource> */
final class CreateOrderProcessor implements ProcessorInterface
{
    public function __construct(private readonly OrderService $orders) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OrderResource
    {
        $order = $this->orders->createOrder($data);   // $data is the validated CreateOrderInput

        return OrderResource::fromEntity($order);
    }
}
```

## State providers (read side)

```php
// ✅ GOOD — provider returns the read model, not the entity
/** @implements ProviderInterface<OrderResource> */
final class OrderItemProvider implements ProviderInterface
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?OrderResource
    {
        $order = $this->orders->find(Uuid::fromString($uriVariables['id']));

        return $order ? OrderResource::fromEntity($order) : null;
    }
}
```

Use the built-in **Pagination** in collection providers; don't return unbounded arrays.

## Validation

Put Symfony Validator constraints on the **input** DTO. API Platform validates automatically before the processor runs and returns RFC 9457 problem+json on failure.

```php
final class CreateOrderInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public string $customerEmail;

    /** @var CreateLineInput[] */
    #[Assert\Valid]
    #[Assert\Count(min: 1)]
    public array $items = [];
}
```

## Operations & security

- Declare operations explicitly; don't accept the default full CRUD set unless you mean it.
- Authorize per-operation with `security`:

```php
new Post(security: "is_granted('ROLE_USER')"),
new Delete(security: "is_granted('ORDER_DELETE', object)"),  // voter on the object
```

## Filters & pagination

```php
#[ApiResource(
    operations: [new GetCollection()],
    paginationItemsPerPage: 20,
    paginationMaximumItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'customerEmail' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt' => 'DESC'])]
final class OrderResource { /* ... */ }
```

For large datasets, see `doctrine-query-optimization` — switch collection providers to keyset pagination.

## Versioning & errors

- Version through the path prefix or a vendor media type; keep `order:read` / `order:write` groups stable.
- Don't hand-build error responses — API Platform emits RFC 9457 problem+json (see `problem-details-rfc9457`). Throw domain exceptions mapped to status codes.

## Gotchas

- Agent slaps `#[ApiResource]` on a Doctrine entity — prefer a DTO resource with provider/processor for non-trivial APIs.
- Agent leaves all CRUD operations enabled by default — declare only the operations you intend to expose.
- Agent forgets serialization `#[Groups]` — every property gets exposed, including sensitive ones.
- Agent puts business logic in the processor — delegate to an application service; the processor just adapts.
- Agent returns the entity from a provider — return the resource DTO.
- Agent disables pagination or returns `findAll()` from a collection provider — keep pagination on.
- Agent hand-rolls error JSON — let API Platform produce problem+json; throw mapped exceptions.
- Agent uses API Platform 2/3 annotations (`@ApiResource`) — use PHP 8 attributes and the v4 metadata classes (`ApiPlatform\Metadata\*`).
