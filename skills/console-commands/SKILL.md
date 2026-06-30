---
name: console-commands
description: >
  Use when creating or refactoring Symfony console commands. Covers the modern
  invokable/attribute command style and the Symfony 8.1+ pattern of putting
  #[AsCommand] on methods so a family of related commands share one service
  class. Also covers typed CLI inputs via value resolvers and #[MapInput] DTOs.
  Use when the task mentions console command, #[AsCommand], bin/console,
  #[Argument], #[Option], or a CLI task.
---

# Symfony Console commands

A console command is just another transport into the service layer — treat the
CLI like a controller: thin, typed at the edges, delegating to services.

> **Version note.** This skill describes two forms. The **default** form
> (class-based `#[AsCommand]`) works on Symfony 7.x. The **8.1+** form
> (method-based `#[AsCommand]`) is the preferred style on Symfony 8.1 and up.
> Check the project's `composer.json` (`symfony/console` constraint) and apply
> the form that matches. When the project is on 8.1+, prefer the method-based
> form.

## Default (Symfony 7.x): one command per class

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('site:scan', 'scan monitored sites for availability')]
final class SiteScanCommand
{
    public function __construct(
        private readonly SiteRepository $sites,
    ) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->success(sprintf('%d sites scanned', \count($this->sites->findAll())));

        return Command::SUCCESS;
    }
}
```

- Use the **invokable** command (`__invoke`) — no `extends Command`, no
  `configure()`/`execute()` boilerplate.
- Inject dependencies through the constructor.

## Symfony 8.1+: a family of commands on one service class

Since Symfony 8.1, `#[AsCommand]` can sit on a **method** instead of a class.
This lets one service class expose a whole family of related commands that share
a single constructor and private helpers.

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SiteService
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
    ) {}

    #[AsCommand('site:add', 'add a site to monitor')]
    public function add(SymfonyStyle $io, #[Argument] SiteUrl $url): int
    {
        // ...
        return Command::SUCCESS;
    }

    #[AsCommand('site:scan', 'scan monitored sites for availability')]
    public function scan(SymfonyStyle $io, #[MapInput] ScanInput $input): int
    {
        // ...
        return Command::SUCCESS;
    }

    // Shared by the sibling commands above — the reason they live together.
    private function findOrFail(SiteUrl $url): Site
    {
        return $this->sites->findOneByUrl($url) ?? throw new \RuntimeException('unknown site');
    }
}
```

- One class holds a **cohesive family** of commands (`site:add`, `site:scan`,
  `site:list`) that share injected dependencies and private helpers.
- The class name reflects its identity as a **service**, not a command holder:
  `SiteService`, not `SiteCommands`. The CLI is just another transport into it.
- **When a method's private helpers stop being shared** with its siblings,
  that's the signal to split it into its own class. Don't build god-classes, and
  don't go back to one-class-per-command.

## Rules

- **Never `extends Command`.** Import `Symfony\Component\Console\Command\Command`
  only for the return constants.
- Return `Command::SUCCESS` / `Command::FAILURE` / `Command::INVALID`.
- `#[AsCommand('name', 'description')]` — **positional** description (second
  argument), not the `description:` named parameter.
- `#[Argument('desc')]` and `#[Option('desc')]` — same convention, positional
  description string. Never `description:`.
- First parameter is typically `SymfonyStyle $io` (when you want its helpers —
  `success`, `table`, `progressBar`) or `OutputInterface $output` (leaner). Pick
  per command.
- `declare(strict_types=1);` in every file.
- Verbose output via `$io->writeln()` gated on `$io->isVerbose()` /
  `$io->isVeryVerbose()`. No `dump()`/`dd()` in committed commands.

## Typed inputs — value resolvers and DTOs

Validation, normalization, and parsing belong in value objects and their
resolvers, **not** in command bodies. (Available with attribute-based commands.)

- **Single typed atoms** (URL, email, ULID, path) → a **value object** with a
  custom `ValueResolverInterface`. The value object's `fromString()` factory
  validates; the resolver maps the raw CLI string to the typed object. Used as
  `#[Argument] SiteUrl $url`.
- **Groups of related inputs** (several args/options that travel together) →
  `#[MapInput]` **DTO** classes. Public properties carry `#[Argument]` /
  `#[Option]`. Validate in **property hooks** (PHP 8.4) or via the Symfony
  Validator — *not* in the constructor, because `#[MapInput]` DTOs are hydrated
  without calling the constructor.
- **Composition** — DTOs can contain other DTOs
  (`ApplyInput { public ScanInput $scan; /* + options */ }`); Symfony merges
  them automatically.
- **Why this matters** — a description like `'url of the site to monitor'` lives
  **once** on the value object or DTO, not repeated across five command methods.
  Same for the validation logic.

```php
final class ScanInput
{
    #[Argument('url of the site to scan')]
    public SiteUrl $url;

    #[Option('follow redirects')]
    public bool $follow = false {
        // validate in a property hook, not the constructor
        set => $value;
    }
}
```

Use Symfony Validator constraints (`#[Assert\Url]`, `#[Assert\Email]`, …) for
validation — idiomatic and consistent with the rest of Symfony.

## Gotchas

- Agent writes `extends Command` with `configure()`/`execute()` boilerplate —
  use the invokable/attribute command instead.
- Agent puts `#[AsCommand]` on a class on Symfony 8.1+ when several related
  commands exist — prefer one service class with method-level `#[AsCommand]`.
- Agent names the class `*Commands` — name it `*Service`; the CLI is a transport
  into the service.
- Agent uses the `description:` named parameter — descriptions are **positional**
  on `#[AsCommand]`, `#[Argument]`, and `#[Option]`.
- Agent parses/validates CLI strings inside the command body — move it into a
  value object + `ValueResolverInterface`, or a `#[MapInput]` DTO.
- Agent puts validation in a `#[MapInput]` DTO **constructor** — it's never
  called; use property hooks (PHP 8.4) or the Validator.
- Agent splits a cohesive command family into one-class-per-command — keep them
  together while they share helpers; split only when they stop sharing.
- Agent returns `0`/`1` integers — use `Command::SUCCESS` / `Command::FAILURE` /
  `Command::INVALID`.
- Agent leaves `dump()`/`dd()` or ungated verbose output — gate on
  `$io->isVerbose()`.
```
