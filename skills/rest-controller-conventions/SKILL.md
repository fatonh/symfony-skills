---
name: rest-controller-conventions
description: >
  Use when writing hand-rolled REST controllers in Symfony (without API Platform) —
  routing attributes, HTTP status codes, request payload mapping, serialization groups,
  pagination, and versioning. Use when generating controllers, JSON endpoints, or
  resource routes.
---

# REST Controller Conventions (Symfony, no API Platform)

## Controller shape

- One `final` class per resource, `#[Route]` prefix on the class.
- Constructor injection of services only (no repositories — see `layered-architecture`).
- Map the request body with `#[MapRequestPayload]` and query params with `#[MapQueryString]` into DTOs.
- Return `JsonResponse` via `$this->json(...)` with explicit status codes.

```php
#[Route('/api/orders')]
final class OrderController extends AbstractController
{
    public function __construct(private readonly OrderService $orders) {}

    #[Route('', methods: ['GET'])]
    public function list(#[MapQueryString] OrderListQuery $query): JsonResponse
    {
        $page = $this->orders->list($query);

        return $this->json($page, context: ['groups' => ['order:read']]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        return $this->json(
            $this->orders->get($id),
            context: ['groups' => ['order:read', 'order:detail']],
        );
    }

    #[Route('', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orders->createOrder($request);

        return $this->json(
            OrderResponse::fromEntity($order),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('order_show', ['id' => $order->getId()])],
            ['groups' => ['order:read']],
        );
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(Uuid $id): JsonResponse
    {
        $this->orders->cancel($id);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

## HTTP status codes

| Situation | Status |
|---|---|
| Successful GET | `200 OK` |
| Resource created | `201 Created` + `Location` header |
| Accepted, processed async | `202 Accepted` |
| Successful DELETE / no body | `204 No Content` |
| Validation failed | `422 Unprocessable Entity` |
| Malformed JSON | `400 Bad Request` |
| Unauthenticated | `401 Unauthorized` |
| Authenticated but forbidden | `403 Forbidden` |
| Not found | `404 Not Found` |
| Conflict (duplicate, version) | `409 Conflict` |

Don't return `200` for everything. Map domain exceptions to status codes in an exception listener (see `problem-details-rfc9457`).

## Serialization groups, not entity exposure

Never serialize a Doctrine entity straight to the client without scoping. Either map to a response DTO or apply `#[Groups]` and pass the group in the serialization context.

```php
// On the entity / DTO
#[Groups(['order:read'])]
private string $customerEmail;

#[Groups(['order:detail'])]   // only on the detail endpoint
private array $internalNotes;
```

## Request mapping & validation

`#[MapRequestPayload]` deserializes **and** validates the DTO (via the Validator). On constraint failure it throws automatically → your exception listener turns it into a `422`.

```php
public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
```

Validate query params the same way with `#[MapQueryString]`. See `dto-and-validation`.

## Pagination

Return a stable envelope, never a bare array, so you can add metadata without a breaking change:

```json
{
  "data": [ ... ],
  "page": 1,
  "perPage": 20,
  "total": 137
}
```

```php
return $this->json(new PaginatedResponse($items, $page, $perPage, $total), context: ['groups' => ['order:read']]);
```

Cap `perPage` server-side. For deep pages, prefer keyset pagination (see `doctrine-query-optimization`).

## Routing conventions

- Plural nouns: `/orders`, `/orders/{id}`, `/orders/{id}/lines`.
- No verbs in paths (`/orders` POST, not `/createOrder`).
- Use HTTP methods for actions; reserve sub-resources for relationships.
- Constrain params: `#[Route('/{id}', requirements: ['id' => Requirement::UUID])]`.

## Gotchas

- Agent returns `$this->json($entity)` without groups — scope with `#[Groups]` or map to a DTO; otherwise lazy associations serialize and leak fields.
- Agent returns `200` for created/deleted resources — use `201` (+ `Location`) and `204`.
- Agent decodes JSON by hand (`json_decode($request->getContent())`) — use `#[MapRequestPayload]` + DTO.
- Agent puts verbs in routes (`/getOrders`, `/createOrder`) — use nouns + HTTP methods.
- Agent returns a bare JSON array for collections — use a paginated envelope.
- Agent catches `NotFoundException` in the controller — let the exception listener map it to `404`.
- Agent skips param requirements — constrain `{id}` to UUID to avoid route collisions.
