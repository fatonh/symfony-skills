---
name: problem-details-rfc9457
description: >
  Use when generating error responses, exception listeners, or custom exceptions in a
  Symfony API. Defines RFC 9457 (problem+json) error format, mapping domain exceptions
  to HTTP status codes, and a single kernel exception listener. Use when the task mentions
  error handling, exception listener, error response, or problem details.
---

# RFC 9457 Problem Details (Symfony)

## The format

Every error response is `application/problem+json` per RFC 9457:

```json
{
  "type": "https://api.example.com/problems/order-not-found",
  "title": "Order not found",
  "status": 404,
  "detail": "No order exists with id 018f...",
  "instance": "/api/orders/018f...",
  "errors": [
    { "field": "items", "message": "This collection should contain 1 element or more." }
  ]
}
```

- `type` — a stable URI identifying the problem class (dereferenceable docs ideally).
- `title` — short, human-readable, **same for every occurrence of this type**.
- `status` — the HTTP status code, duplicated in the body.
- `detail` — specific to this occurrence.
- `instance` — the request URI that produced it.
- Extension members (`errors`, `traceId`, …) are allowed and encouraged.

## Domain exceptions carry their own HTTP semantics

Define a base exception that knows its status and problem type. Throw it from services/domain; never build error JSON in a controller.

```php
abstract class DomainException extends \RuntimeException
{
    abstract public function statusCode(): int;

    abstract public function problemType(): string;

    public function title(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }
}

final class OrderNotFoundException extends DomainException
{
    public function __construct(Uuid $id)
    {
        parent::__construct("No order exists with id {$id->toRfc4122()}");
    }

    public function statusCode(): int { return Response::HTTP_NOT_FOUND; }

    public function problemType(): string { return 'https://api.example.com/problems/order-not-found'; }

    public function title(): string { return 'Order not found'; }
}
```

## One exception listener, not try/catch everywhere

```php
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ProblemDetailsExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $request = $event->getRequest();

        $problem = match (true) {
            $e instanceof DomainException             => $this->fromDomain($e, $request),
            $e instanceof ValidationFailedException   => $this->fromValidation($e, $request),
            $e instanceof HttpExceptionInterface      => $this->fromHttp($e, $request),
            default                                   => $this->fromUnexpected($e, $request),
        };

        if ($problem['status'] >= 500) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        }

        $event->setResponse(new JsonResponse(
            $problem,
            $problem['status'],
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function fromDomain(DomainException $e, Request $request): array
    {
        return [
            'type'     => $e->problemType(),
            'title'    => $e->title(),
            'status'   => $e->statusCode(),
            'detail'   => $e->getMessage(),
            'instance' => $request->getPathInfo(),
        ];
    }

    private function fromValidation(ValidationFailedException $e, Request $request): array
    {
        $errors = [];
        foreach ($e->getViolations() as $v) {
            $errors[] = ['field' => $v->getPropertyPath(), 'message' => $v->getMessage()];
        }

        return [
            'type'     => 'https://api.example.com/problems/validation-error',
            'title'    => 'Validation failed',
            'status'   => Response::HTTP_UNPROCESSABLE_ENTITY,
            'detail'   => 'The request payload did not pass validation.',
            'instance' => $request->getPathInfo(),
            'errors'   => $errors,
        ];
    }

    private function fromHttp(HttpExceptionInterface $e, Request $request): array
    {
        return [
            'type'     => 'about:blank',
            'title'    => Response::$statusTexts[$e->getStatusCode()] ?? 'Error',
            'status'   => $e->getStatusCode(),
            'detail'   => $e->getMessage(),
            'instance' => $request->getPathInfo(),
        ];
    }

    private function fromUnexpected(\Throwable $e, Request $request): array
    {
        return [
            'type'     => 'about:blank',
            'title'    => 'Internal Server Error',
            'status'   => Response::HTTP_INTERNAL_SERVER_ERROR,
            // Never leak internals in prod.
            'detail'   => $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
            'instance' => $request->getPathInfo(),
        ];
    }
}
```

## Don't leak internals

- In `prod`, the `detail` for 5xx must be generic — never the exception message or stack trace.
- Log the full exception server-side with a `traceId`; return that id so support can correlate.
- Never echo SQL, file paths, or class names to the client.

## API Platform note

API Platform 4 already emits problem+json. You only need this listener for **non-API-Platform** controllers, or to customize the problem `type`/extensions. Map domain exceptions to status codes via `config/packages/api_platform.yaml` `exception_to_status`.

## Gotchas

- Agent builds `['error' => '...']` ad-hoc JSON — use the RFC 9457 shape with `type/title/status/detail/instance`.
- Agent sets `Content-Type: application/json` for errors — use `application/problem+json`.
- Agent `try/catch`es in each controller — centralize in one `KernelEvents::EXCEPTION` listener.
- Agent leaks `$e->getMessage()` / stack traces on 5xx in prod — gate behind `kernel.debug`.
- Agent uses HTTP `400` for validation errors — Symfony convention is `422 Unprocessable Entity`.
- Agent forgets to log 5xx — log server-side; only the safe summary goes to the client.
- Agent reinvents an exception hierarchy per feature — extend the shared `DomainException` base.
