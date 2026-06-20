---
name: dto-and-validation
description: >
  Use when creating request/response DTOs, applying Symfony Validator constraints, mapping
  payloads with #[MapRequestPayload]/#[MapQueryString], or mapping DTOs to/from entities.
  Use when the task mentions DTO, request validation, form input, or the Validator component.
---

# DTOs & Validation (Symfony)

## DTOs are immutable, typed, and split by direction

- **Request DTO** — carries input, holds validation constraints, never touches the DB.
- **Response DTO** — carries output, has a `fromEntity()` factory, no constraints.
- Never reuse one class for both directions.
- Use `readonly` promoted properties; default empty collections to `[]`, never null.

```php
// ✅ Request DTO — constraints live here
final class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $customerEmail = '',

        /** @var CreateLineRequest[] */
        #[Assert\Valid]                          // cascade into nested DTOs
        #[Assert\Count(min: 1, minMessage: 'An order needs at least one line.')]
        public readonly array $items = [],
    ) {}
}

final class CreateLineRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $productId = '',

        #[Assert\Positive]
        public readonly int $quantity = 0,
    ) {}
}
```

```php
// ✅ Response DTO — no constraints, has a factory
final readonly class OrderResponse
{
    /** @param LineItemResponse[] $items */
    public function __construct(
        public string $id,
        public string $status,
        public array $items,
    ) {}

    public static function fromEntity(Order $order): self
    {
        return new self(
            $order->getId()->toRfc4122(),
            $order->getStatus()->value,
            array_map(LineItemResponse::fromEntity(...), $order->getItems()->toArray()),
        );
    }
}
```

## `#[MapRequestPayload]` does deserialization + validation

Don't decode JSON manually and don't call the validator yourself in the controller. The attribute handles both; on failure it throws `ValidationFailedException` → your exception listener returns `422` (see `problem-details-rfc9457`).

```php
#[Route('/api/orders', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
{
    // $request is already deserialized AND validated here.
    $order = $this->orderService->createOrder($request);
    return $this->json(OrderResponse::fromEntity($order), Response::HTTP_CREATED);
}

// Query strings -> DTO
public function list(#[MapQueryString] OrderListQuery $query): JsonResponse { /* ... */ }
```

```php
// ❌ BAD — manual decode + manual validate + manual error response in the controller
public function create(Request $request, ValidatorInterface $validator): JsonResponse
{
    $dto = $this->serializer->deserialize($request->getContent(), CreateOrderRequest::class, 'json');
    $errors = $validator->validate($dto);
    if (count($errors) > 0) {
        return $this->json(['errors' => (string) $errors], 400); // ad-hoc, wrong status
    }
    // ...
}
```

## Built-in constraints over custom code

Reach for the standard constraints before writing logic:

`NotBlank`, `NotNull`, `Length`, `Range`, `Positive`, `PositiveOrZero`, `Email`, `Uuid`, `Choice`, `Count`, `Valid` (cascade), `Type`, `GreaterThan`, `Regex`, `When` (conditional), `Unique`.

```php
#[Assert\Choice(callback: [OrderStatus::class, 'values'])]
public readonly string $status;

#[Assert\When(
    expression: 'this.shippingRequired === true',
    constraints: [new Assert\NotBlank()],
)]
public readonly ?string $shippingAddress = null;
```

## Custom constraints when a rule repeats

For domain rules used in more than one place, write a constraint + validator rather than inlining checks.

```php
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueCustomerEmail extends Constraint
{
    public string $message = 'An account with email "{{ value }}" already exists.';
}

final class UniqueCustomerEmailValidator extends ConstraintValidator
{
    public function __construct(private readonly CustomerRepository $customers) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($value === null || $value === '') {
            return; // let NotBlank handle emptiness
        }
        if ($this->customers->existsByEmail($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
```

## Mapping DTO → entity

Keep mapping out of controllers. Construct the entity through its factory/behavior methods (see `domain-driven-design`), passing scalar values from the DTO. Don't blindly hydrate an entity from request data — that's how mass-assignment bugs happen.

```php
// In the service
$order = Order::create($request->customerEmail);
foreach ($request->items as $line) {
    $order->addItem(Uuid::fromString($line->productId), $line->quantity);
}
```

## Gotchas

- Agent reuses one class for request and response — split them; constraints belong only on the request DTO.
- Agent decodes JSON and calls `$validator->validate()` by hand in the controller — use `#[MapRequestPayload]`.
- Agent returns `400` for validation errors with ad-hoc JSON — let the exception listener produce `422` problem+json.
- Agent forgets `#[Assert\Valid]` on nested DTO arrays — nested constraints won't run without it.
- Agent writes manual `if (empty(...))` checks in the service for things constraints already cover.
- Agent hydrates a Doctrine entity directly from request data (mass assignment) — go through the entity's factory/behavior methods.
- Agent puts validation on the entity instead of the request DTO — entities enforce invariants; DTOs validate input shape.
- Agent makes DTO properties nullable + mutable — prefer `readonly` with sensible defaults.
