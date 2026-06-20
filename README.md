# Symfony Skills for Claude Code

> Production-ready [Claude Code](https://claude.com/claude-code) skills that teach the agent **your** Symfony conventions — not generic PHP from a 2019 blog post.

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

## Install

```bash
# 1. Install Claude Code
npm install -g @anthropic-ai/claude-code

# 2. Create the skills directory in your project
mkdir -p .claude/skills

# 3. Copy the skills you want
cp -r symfony-skills/skills/doctrine-orm .claude/skills/
cp -r symfony-skills/skills/api-platform-resources .claude/skills/
```

Claude Code auto-discovers anything in `.claude/skills/` and loads it when the task matches the skill's `description` trigger.

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
