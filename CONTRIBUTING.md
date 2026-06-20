# Contributing

Thanks for improving Symfony Skills.

## The most valuable contribution: Gotchas

When Claude generates a wrong Symfony pattern in your project, that's a data point. Add it to the **Gotchas** section of the relevant skill:

```markdown
- Agent <does the wrong thing> — <do this instead>
```

Keep each line one mistake → one correction. This is what makes the skills sharp.

## Adding a new skill

```
skills/<kebab-case-name>/
├── SKILL.md      # required
├── examples/     # optional, ✅/❌ pairs
└── templates/    # optional, copy-paste starters
```

### SKILL.md frontmatter

```yaml
---
name: <kebab-case-name>            # must match the directory name
description: >                     # the trigger — when should Claude load this?
  Use when <task description>. Defines <what conventions it carries>.
---
```

The `description` is matched against the user's task, so write it as "Use when …". Be concrete about the triggers (controllers, entities, migrations, error handlers) — vague descriptions don't fire.

### Style

- **PHP 8.3+**, Symfony 7, Doctrine ORM 3, attributes (not annotations/XML/YAML mapping).
- Show **contrasting code**: `// ✅ GOOD` and `// ❌ BAD` blocks.
- Prefer real, runnable snippets over prose.
- End every `SKILL.md` with a **Gotchas** list.

## Conventions for the code in skills

- `declare(strict_types=1);` in every PHP file.
- Constructor property promotion, `readonly` where it fits.
- Enums (backed) over class constants.
- `Symfony\Component\Uid\Uuid` for IDs, never auto-increment in public APIs.
- Run `vendor/bin/php-cs-fixer` mentally — match Symfony coding standard.

## PRs

Small, focused PRs. One skill or one Gotcha set per PR where possible.
