---
name: bundle-organization
description: >
  Use when structuring a Symfony project's src/ directory, organizing code into modules,
  configuring services, or deciding where a class belongs. Covers the modular monolith
  layout, service configuration, and config/ conventions for Symfony 7 (Flex, no app bundle).
---

# Project / Bundle Organization (Symfony 7)

## Modern Symfony apps are NOT bundles

Since Symfony 4, application code lives in `src/` under the `App\` namespace — **not** in a custom bundle. Create a bundle only for **reusable library code shared across projects**. For an application, organize `src/` by **feature/module**, not by technical type.

```
src/
├── Kernel.php
├── Sales/                       # bounded context / module
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── UI/                      # controllers, console commands, CLI
├── Billing/
│   └── ...
└── Shared/                      # cross-cutting kernel: base value objects, bus, ids
```

```
config/
├── packages/                    # per-bundle config (doctrine.yaml, security.yaml, ...)
│   └── prod/ · dev/ · test/     # env overrides
├── routes/                      # route imports
├── services.yaml                # app service wiring
└── bundles.php                  # registered bundles
```

### ❌ Don't organize purely by technical layer at the top level

```
src/
├── Controller/        # ❌ for a large app, everything-in-one-folder doesn't scale
├── Entity/
├── Repository/
└── Service/
```

This is fine for a tiny app, but in a domain of any size it scatters one feature across five folders. Group by **module first**, then layer inside.

## Service configuration

Rely on Symfony's defaults — **autowiring** + **autoconfiguration** + **resource autodiscovery**:

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Kernel.php'
            - '../src/**/Domain/**/{Entity,ValueObject}'   # plain objects, not services
```

- Bind an interface to an implementation only when there's a choice to make:

```yaml
    App\Sales\Domain\OrderRepository: '@App\Sales\Infrastructure\Doctrine\DoctrineOrderRepository'
```

- Inject scalar config with `#[Autowire]` at the point of use — don't sprinkle `%env()%` reads:

```php
public function __construct(
    #[Autowire('%env(int:ORDER_TTL_SECONDS)%')] private readonly int $orderTtl,
    #[Autowire(service: 'app.payment.http_client')] private readonly HttpClientInterface $client,
) {}
```

- Tag-based collection injection with `#[AutowireIterator]` / `#[TaggedIterator]` for strategy sets:

```php
public function __construct(
    #[AutowireIterator('app.payment_gateway')] private readonly iterable $gateways,
) {}
```

## Where does a class go?

| Class | Location |
|---|---|
| Doctrine entity / aggregate | `src/<Module>/Domain/` (or `Domain/Model/`) |
| Repository interface (port) | `src/<Module>/Domain/` |
| Repository Doctrine impl | `src/<Module>/Infrastructure/Doctrine/` |
| Application service / handler | `src/<Module>/Application/` |
| Controller | `src/<Module>/UI/Http/` (or top-level `Controller/` in small apps) |
| Console command | `src/<Module>/UI/Cli/` |
| DTO (request/response) | `src/<Module>/UI/Http/Dto/` |
| Value object | `src/<Module>/Domain/` |
| Custom Doctrine type | `src/Shared/Infrastructure/Doctrine/Type/` |

## Parameters and environment

- Secrets via Symfony's encrypted secrets vault (`secrets:set`) or `.env.local` — never commit real secrets.
- Type-cast env vars: `%env(int:…)%`, `%env(bool:…)%`, `%env(json:…)%`.
- Don't read `$_ENV` / `getenv()` in app code — inject the value.

## When you DO write a bundle

Only for shareable libraries. Modern bundles use `AbstractBundle`:

```php
final class AcmePaymentBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void { /* config tree */ }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');
    }
}
```

## Gotchas

- Agent generates a custom `AppBundle` for application code — modern apps use `src/` under `App\`, no app bundle.
- Agent dumps everything into top-level `Controller/`, `Entity/`, `Service/` for a large domain — organize by module first.
- Agent registers every service manually in `services.yaml` — rely on autowiring + autodiscovery; configure only the exceptions.
- Agent calls `getenv()` / reads `$_ENV` inside a service — inject the value via `#[Autowire('%env(...)%')]`.
- Agent puts entities/value objects into the service container — exclude `Domain` plain objects from autoregistration.
- Agent commits secrets in `.env` — use the secrets vault or `.env.local`.
