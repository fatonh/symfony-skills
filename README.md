# Symfony Skills for Claude Code

> Production-ready [Claude Code](https://claude.com/claude-code) skills that teach the agent **your** Symfony conventions — not generic PHP from a 2019 blog post.

**Jump to:** [Quick start](#quick-start) · [Before / After](#before--after) · [Skill catalog](#skill-catalog-16) · [Why](#why) · [Stack assumptions](#stack-assumptions) · [Anatomy of a skill](#anatomy-of-a-skill) · [Contributing](#contributing)

## Why

AI coding agents are fluent in scripting languages but hallucinate in Symfony. Left alone, an agent will:

- inject dependencies via the service container instead of the constructor,
- return Doctrine entities straight out of a controller,
- reinvent an exception hierarchy instead of using `HttpException`,
- write `EAGER` associations and N+1 queries,
- put business logic in controllers and persistence in services.

A **skill** is a markdown file the agent reads *before* it touches your code. It encodes your stack, your patterns, and the exact mistakes agents make in that domain (the **Gotchas** section).

## Stack assumptions

These skills target a modern Symfony stack:

- **Symfony 7.x**, **PHP 8.3+** (attributes, readonly classes, enums, first-class callable syntax)
- **Doctrine ORM 3** with attribute mapping and migrations
- **API Platform 4** for REST/GraphQL resources
- **Symfony Serializer + Validator** for DTOs
- **Symfony Messenger** for async / CQRS
- **Lexik JWT** + Symfony Security for authentication
- **PHPUnit 11** + Foundry/Zenstruck for tests

Most patterns apply to Symfony 6.4 LTS too; differences are noted inline.

### Tested against

The code in these skills was written and reviewed against these exact versions. Newer patch/minor releases are expected to work; if something breaks on a different version, open an issue.

| Component | Version |
|---|---|
| PHP | 8.3 |
| Symfony | 7.2.x |
| Doctrine ORM | 3.3 |
| Doctrine Migrations | 3.8 |
| API Platform | 4.0 |
| Symfony Messenger | 7.2 |
| LexikJWTAuthenticationBundle | 3.x |
| PHPUnit | 11.x |
| zenstruck/foundry | 2.x |

### Opinionated by design — and easy to override

These skills ship **sensible defaults**, not the only way. Each `SKILL.md` states *why* it picks a pattern, so when your project differs you can override it: edit the copied skill in your `.claude/skills/` (it's yours now), or add a project-specific note to its **Gotchas**. Common override points — UUID vs. auto-increment IDs, API Platform vs. hand-rolled controllers, Lexik vs. another JWT bundle, Foundry vs. fixture files — are called out in the relevant skill.

## Quick start

```bash
# 1. Install Claude Code (skip if you already have it)
npm install -g @anthropic-ai/claude-code

# 2. From the root of YOUR Symfony project, create the skills directory
mkdir -p .claude/skills

# 3. Pull in the skills you want (clone this repo somewhere first)
git clone https://github.com/fatonh/symfony-skills /tmp/symfony-skills
cp -r /tmp/symfony-skills/skills/doctrine-orm            .claude/skills/
cp -r /tmp/symfony-skills/skills/api-platform-resources  .claude/skills/
cp -r /tmp/symfony-skills/skills/dto-and-validation      .claude/skills/

# ...or install the whole set
cp -r /tmp/symfony-skills/skills/* .claude/skills/
```

Claude Code auto-discovers anything in `.claude/skills/` and loads a skill automatically when your task matches its `description` trigger — no flags, no config.

**Verify it loaded:** inside Claude Code, run `/skills` to list discovered skills, or just ask *"add an orders endpoint"* and watch it pull in `layered-architecture` / `dto-and-validation` before writing code.

> **Commit the skills to your repo.** Putting `.claude/skills/` under version control means every teammate (and every CI agent run) gets the same conventions. That's the point — the skills *are* your team's shared standards.

## Skill catalog (16)

### Architecture
| Skill | Use when |
|---|---|
| [`layered-architecture`](skills/layered-architecture) | Generating controllers, services, repositories — enforces layer boundaries |
| [`hexagonal-architecture`](skills/hexagonal-architecture) | Ports & adapters, keeping the domain framework-free |
| [`domain-driven-design`](skills/domain-driven-design) | Aggregates, value objects, domain events, ubiquitous language |
| [`bundle-organization`](skills/bundle-organization) | Structuring `src/`, modular monolith, service config |

### API design
| Skill | Use when |
|---|---|
| [`api-platform-resources`](skills/api-platform-resources) | Exposing entities/DTOs as `#[ApiResource]`, operations, state processors |
| [`rest-controller-conventions`](skills/rest-controller-conventions) | Hand-rolled REST controllers, status codes, serialization groups |
| [`problem-details-rfc9457`](skills/problem-details-rfc9457) | Error responses, exception listeners, RFC 9457 problem+json |
| [`dto-and-validation`](skills/dto-and-validation) | Request/response DTOs, the Validator, mapping to entities |

### Persistence
| Skill | Use when |
|---|---|
| [`doctrine-orm`](skills/doctrine-orm) | Entities, associations, repositories, enums, UUIDs |
| [`doctrine-migrations`](skills/doctrine-migrations) | Schema changes, reversible migrations, deploy safety |
| [`doctrine-query-optimization`](skills/doctrine-query-optimization) | N+1, DQL, pagination, keyset, read models |
| [`transactional-patterns`](skills/transactional-patterns) | Transaction boundaries, flush strategy, optimistic locking |

### Security
| Skill | Use when |
|---|---|
| [`security-authentication`](skills/security-authentication) | Firewalls, authenticators, voters, password hashing |
| [`jwt-authentication`](skills/jwt-authentication) | Stateless JWT auth with Lexik, refresh tokens |

### Async & testing
| Skill | Use when |
|---|---|
| [`messenger-async`](skills/messenger-async) | Commands/queries, async transports, handlers, retries |
| [`testing-pyramid`](skills/testing-pyramid) | Unit / integration / functional tests, factories, the test DB |

## Before / After

The same prompt — *"add a POST endpoint to create an order"* — produces very different code depending on whether the skills are loaded.

### ❌ Without skills

The agent reaches for whatever it's seen most on the internet: fat controller, manual JSON decoding, direct repository access, an entity serialized straight to the client, and `200 OK` for a created resource.

```php
#[Route('/api/orders', methods: ['POST'])]
public function create(Request $request, OrderRepository $orders, EntityManagerInterface $em): JsonResponse
{
    $data = json_decode($request->getContent(), true);   // manual, unvalidated
    if (empty($data['items'])) {
        throw new \RuntimeException('No items');          // domain rule in controller
    }
    $order = new Order($data['customerEmail']);
    $em->persist($order);
    $em->flush();                                          // transaction in controller
    return $this->json($order);                            // entity leaks, wrong status
}
```

### ✅ With `layered-architecture` + `dto-and-validation` + `rest-controller-conventions`

The agent writes a thin controller: a validated request DTO, one service call, a response DTO, and correct HTTP semantics.

```php
#[Route('/api/orders', methods: ['POST'])]
public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
{
    $order = $this->orderService->createOrder($request);  // logic + transaction in service

    return $this->json(
        OrderResponse::fromEntity($order),                // DTO, not the entity
        Response::HTTP_CREATED,                            // 201 + Location header
        ['Location' => $this->generateUrl('order_show', ['id' => $order->getId()])],
    );
}
```

| Concern | Without skills | With skills |
|---|---|---|
| Request handling | `json_decode`, unvalidated | `#[MapRequestPayload]` + validated DTO |
| Business logic | in the controller | in the service |
| Persistence | `EntityManager` in controller | owned by the service |
| Response body | raw Doctrine entity | response DTO with serialization groups |
| Status code | `200 OK` | `201 Created` + `Location` |
| Errors | `RuntimeException` → 500 | domain exception → RFC 9457 problem+json |

The skills don't make the agent *smarter* — they make it consistent with **your** conventions, every time, without you re-explaining them in each prompt.

## Anatomy of a skill

```
skills/<name>/
├── SKILL.md      # trigger (frontmatter) + conventions + Gotchas
├── examples/     # contrasting ✅ good / ❌ bad code
└── templates/    # copy-paste starting points
```

The **Gotchas** section at the bottom of each `SKILL.md` is the highest-value part: it lists the exact wrong patterns agents reach for, so they get corrected before the code is written.

## Contributing

If Claude generates a wrong pattern for your project, add it to the relevant skill's **Gotchas** section and open a PR. Crowdsourced corrections improve the skills for everyone.

## License

MIT
