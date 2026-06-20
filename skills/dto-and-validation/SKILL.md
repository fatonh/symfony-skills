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

## Complex & nested payloads

Real APIs rarely take a flat object. The rules below keep deep/nested JSON validating correctly.

### `#[Assert\Valid]` must repeat at every level

Validation does **not** recurse automatically. Each property that holds a nested DTO (object *or* array of objects) needs its own `#[Assert\Valid]`, all the way down — miss one and the constraints inside that branch silently don't run.

```php
final class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotBlank, Assert\Email]
        public readonly string $customerEmail = '',

        #[Assert\Valid]                              // cascade into the address object
        public readonly ?AddressRequest $shippingAddress = null,

        /** @var OrderLineRequest[] */
        #[Assert\Valid]                              // cascade into EACH array element
        #[Assert\Count(min: 1)]
        public readonly array $items = [],
    ) {}
}

final class OrderLineRequest
{
    public function __construct(
        #[Assert\Uuid]
        public readonly string $productId = '',

        #[Assert\Positive]
        public readonly int $quantity = 0,

        /** @var DiscountRequest[] */
        #[Assert\Valid]                              // still required two levels deep
        public readonly array $discounts = [],
    ) {}
}
```

### Typed nested DTOs deserialize automatically

The Serializer hydrates nested DTOs from the property **type hint** — no manual wiring. Type the property as the DTO (or `DtoType[]` via the `@var` PHPDoc for arrays) and `#[MapRequestPayload]` builds the whole graph.

```php
// JSON: { "customerEmail": "...", "shippingAddress": { "city": "..." }, "items": [ { ... } ] }
public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
// $request->shippingAddress is an AddressRequest; $request->items is OrderLineRequest[]
```

For arrays of objects you **must** give the `@var ItemType[]` PHPDoc — the Serializer needs it to know what to hydrate each element into; without it you get an array of `stdClass`/arrays and the nested constraints never fire.

### Polymorphic / discriminated payloads

When a field can be one of several shapes (`{"type": "card", ...}` vs `{"type": "paypal", ...}`), use a `#[DiscriminatorMap]` on an abstract base so the Serializer picks the right concrete DTO.

```php
#[DiscriminatorMap(typeProperty: 'type', mapping: [
    'card'   => CardPaymentRequest::class,
    'paypal' => PaypalPaymentRequest::class,
])]
abstract class PaymentRequest {}

final class CardPaymentRequest extends PaymentRequest
{
    public function __construct(
        #[Assert\CardScheme(Assert\CardScheme::VISA)]
        public readonly string $pan = '',
    ) {}
}
```

### When `#[MapRequestPayload]` isn't enough → a custom ValueResolver

`#[MapRequestPayload]` deserializes one DTO from the body. When you need to build a DTO from **several request parts** (body + route params + headers), apply non-trivial pre-processing, or support a payload shape the Serializer can't express, write a `ValueResolverInterface` instead of decoding in the controller.

```php
final class CreateOrderRequestResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {}

    /** @return iterable<CreateOrderRequest> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CreateOrderRequest::class) {
            return [];
        }

        $dto = $this->serializer->deserialize($request->getContent(), CreateOrderRequest::class, 'json');

        // Enrich from other request parts the body doesn't carry.
        $dto = $dto->withTenantId($request->attributes->get('tenantId'));

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            throw new ValidationFailedException($dto, $violations); // -> 422 via the exception listener
        }

        yield $dto;
    }
}
```

```php
// Controller stays thin — the resolver does the work, just like #[MapRequestPayload].
public function create(CreateOrderRequest $request): JsonResponse { /* ... */ }
```

Throw `ValidationFailedException` (not an ad-hoc response) so your problem-details listener still produces consistent `422` output. Reserve custom resolvers for cases the attribute genuinely can't handle — don't reimplement `#[MapRequestPayload]`.

### Limit nesting depth on untrusted input

Deeply nested JSON is a denial-of-service vector. Cap depth at the edge (`json_decode($json, depth: 32)` semantics) or via the Serializer's `json_decode_options`/`max_depth` context, and keep payloads shallow by design.

> **OpenAPI / contract-first:** if you generate or hand-maintain an OpenAPI schema, keep the DTO and the schema in lockstep so the documented contract matches what's actually validated. With API Platform the schema is generated **from** the resource/DTO automatically — see the `api-platform-resources` skill.

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
- Agent adds `#[Assert\Valid]` at the top level but not on deeper nested DTOs — cascade doesn't recurse; repeat it at every level.
- Agent omits the `@var ItemType[]` PHPDoc on an array property — the Serializer hydrates `stdClass`/arrays and nested validation never fires.
- Agent hand-writes `if ($data['type'] === 'card')` branching for polymorphic payloads — use `#[DiscriminatorMap]` and concrete DTO subclasses.
- Agent decodes the body in the controller when the DTO needs data from route/headers too — write a `ValueResolverInterface` instead.
- Agent's custom resolver returns an ad-hoc error response — throw `ValidationFailedException` so the problem-details listener produces a consistent `422`.
- Agent leaves nesting depth unbounded on public input — cap decode depth to avoid a DoS vector.
- Agent writes manual `if (empty(...))` checks in the service for things constraints already cover.
- Agent hydrates a Doctrine entity directly from request data (mass assignment) — go through the entity's factory/behavior methods.
- Agent puts validation on the entity instead of the request DTO — entities enforce invariants; DTOs validate input shape.
- Agent makes DTO properties nullable + mutable — prefer `readonly` with sensible defaults.
