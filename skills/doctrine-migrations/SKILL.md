---
name: doctrine-migrations
description: >
  Use when changing the database schema in Symfony — generating Doctrine migrations,
  writing reversible up()/down(), data migrations, and deploy-safe (zero-downtime)
  changes. Use when the task mentions migration, schema change, ALTER TABLE, or
  doctrine:migrations.
---

# Doctrine Migrations (Symfony)

## Generate, don't hand-write the schema diff

After changing entity mapping, **generate** the migration from the diff, then review it:

```bash
php bin/console make:migration          # diffs entities vs DB, scaffolds up()/down()
php bin/console doctrine:migrations:migrate
```

Never edit the schema with `doctrine:schema:update --force` outside local throwaway work — it bypasses migration history. Always review the generated SQL before committing; the diff tool sometimes proposes destructive or reordering changes you didn't intend.

## Anatomy of a migration

```php
final class Version20260620120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status column to orders and index it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE orders ADD status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        $this->addSql('CREATE INDEX idx_orders_status ON orders (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_orders_status');
        $this->addSql('ALTER TABLE orders DROP status');
    }
}
```

- **`down()` must reverse `up()`** exactly — don't leave it empty or `throw`. A migration you can't roll back is a deploy you can't undo.
- One logical change per migration — don't bundle unrelated DDL.
- Keep migrations **immutable** once merged/deployed. Never edit a migration that has run anywhere; write a new one.

## Zero-downtime / deploy-safe changes

When the app is running during deploy, schema changes must be backward compatible with the **currently deployed code**.

| Change | Safe? | Do this |
|---|---|---|
| Add nullable column | ✅ | Single migration |
| Add NOT NULL column | ⚠️ | Add nullable → backfill → set NOT NULL (3 steps, often 2 deploys) |
| Rename column | ❌ | Add new → copy → deploy code using new → drop old |
| Drop column | ⚠️ | Deploy code that stops using it **first**, then drop |
| Add index on large table | ⚠️ | `CREATE INDEX CONCURRENTLY` (Postgres) — outside a transaction |

```php
// Postgres concurrent index — must run outside the migration's wrapping transaction
public function up(Schema $schema): void
{
    $this->addSql('CREATE INDEX CONCURRENTLY idx_orders_created_at ON orders (created_at)');
}

public function isTransactional(): bool
{
    return false; // CONCURRENTLY can't run inside a transaction
}
```

The **expand/contract** pattern: expand the schema (additive, both old and new code work), deploy code, then contract (remove the old) in a later migration.

## Data migrations

Transforming rows belongs in `up()` too, but keep it **set-based SQL**, not entity hydration (the entity classes may not match the historical schema):

```php
public function up(Schema $schema): void
{
    $this->addSql("UPDATE orders SET status = 'pending' WHERE status IS NULL");
    $this->addSql('ALTER TABLE orders ALTER COLUMN status SET NOT NULL');
}
```

Don't `use App\Entity\Order` inside a migration — entities evolve, migrations are frozen in time. For heavy backfills that can't be one statement, prefer a separate idempotent console command run during deploy.

## Conventions

- One migration file per PR that changes the schema; commit it alongside the entity change.
- Verify `up()` then `down()` then `up()` runs clean locally before pushing.
- Name migrations by what they do (via `getDescription()`), since the class name is just a timestamp.
- In CI, fail the build if `make:migration` would produce a non-empty diff (schema and entities are out of sync).

```bash
php bin/console doctrine:migrations:migrate --dry-run   # print SQL without executing
php bin/console doctrine:schema:validate                 # entities ⇄ DB in sync?
```

## Gotchas

- Agent runs `doctrine:schema:update --force` instead of generating a migration — always use `make:migration` + `migrate`.
- Agent leaves `down()` empty or throwing — make it reverse `up()`.
- Agent adds a `NOT NULL` column with no default to a populated table — add nullable, backfill, then enforce NOT NULL.
- Agent edits an already-deployed migration — migrations are immutable; write a new one.
- Agent imports entity classes inside a migration — use raw SQL; entities don't match historical schemas.
- Agent renames a column in one step during a live deploy — use expand/contract.
- Agent creates an index on a big Postgres table without `CONCURRENTLY` (+ `isTransactional(): false`) — it locks the table.
- Agent bundles several unrelated schema changes into one migration — keep them separate.
