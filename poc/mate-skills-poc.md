# PoC: Symfony Mate (detect) + convention-skills (remediate)

**Goal:** prove that an AI agent does meaningfully better when it has BOTH
- **Mate** — runtime eyes (profiler shows the N+1), and
- a **convention-skill** (`doctrine-query-optimization`) — the correct fix,

than with either alone. One endpoint, one deliberate N+1, one before/after.

**Thesis being tested (Johannes's framing):** *better context, not more context.*
Detect + remediate, which neither half does alone.

**Scope discipline:** N+1 case ONLY. Resist adding more scenarios or skills until
this one works end to end.

---

## Prerequisites

You need a working PHP toolchain. Any ONE of:
- local `php` 8.3 + `composer` + `symfony` CLI on PATH, **or**
- DDEV (`ddev` — recommended, matches Mate's documented Docker path), **or**
- Docker + a PHP container.

> On this Mac, `php`/`composer`/`symfony` were not on PATH at PoC-writing time.
> Install via Homebrew (`brew install php composer symfony-cli`) or use DDEV
> before Stage 0.

Check:
```bash
php -v          # expect 8.3.x
composer -V
symfony version # optional but handy
```

---

## Stage 0 — Smoke test: does Mate talk to Claude Code at all? (~30–60 min)

**Do this FIRST.** This is the only amber-risk step. If it works, the rest is downhill.
If it doesn't, you've spent 30 minutes — not 3 evenings — and the friction itself is a
finding worth reporting to Johannes.

### 0.1 Spin up the throwaway test app
```bash
cd /tmp
symfony new mate-smoke --version=7.2 --webapp
cd mate-smoke
```
(`--webapp` pulls in Twig, profiler, Doctrine, etc. — we want the profiler.)

### 0.2 Install Mate + the Symfony bridge
```bash
composer require --dev symfony/ai-mate
vendor/bin/mate init
composer require --dev symfony/ai-symfony-mate-extension
vendor/bin/mate discover        # generates mate/extensions.php
composer dump-autoload
```

### 0.3 Confirm Mate sees its own tools (no AI yet)
```bash
vendor/bin/mate debug:extensions --show-all
vendor/bin/mate mcp:tools:list
vendor/bin/mate mcp:tools:list --filter="*profiler*"
```
✅ **Checkpoint A:** you see `symfony-profiler-list`, `symfony-profiler-latest`,
`symfony-services`, etc. If not, stop and fix discovery before going further.

### 0.4 Generate one profiler profile to read
```bash
symfony serve -d            # or: php -S localhost:8000 -t public
curl -s http://localhost:8000/ > /dev/null
vendor/bin/mate mcp:tools:call symfony-profiler-latest '{}'
```
✅ **Checkpoint B:** the call returns real profile JSON (route, query count, time).
This proves Mate can read the running app. Half the PoC's value is already demonstrable
from the CLI alone, even before wiring the agent.

### 0.5 Register Mate as an MCP server in Claude Code — THE make-or-break step
Find Mate's MCP server command (the stdio entrypoint):
```bash
vendor/bin/mate --help        # look for an mcp/serve/server subcommand
vendor/bin/mate list          # list all commands
```
Then add it to Claude Code (project scope), e.g.:
```bash
# adjust the final command to whatever Mate's serve entrypoint actually is
claude mcp add symfony-mate -- /tmp/mate-smoke/vendor/bin/mate mcp:serve
```
Verify inside Claude Code:
```
/mcp            # should list "symfony-mate" with its tools
```
✅ **Checkpoint C (the big one):** in a Claude Code session opened in
`/tmp/mate-smoke`, ask: *"Use the Mate tools to show me the latest profiler profile."*
The agent should call `symfony-profiler-latest` and report back.

> If Checkpoint C fails: capture the exact error / behavior. That blocker IS the
> contribution — it's literally the "share skills in our ecosystem easily" problem
> Johannes named. Report it and stop; don't sink more evenings into polish.

---

## Stage 1 — Build the deliberate N+1 app (~1–2 h)

Use the smoke app or a fresh `symfony new mate-poc --version=7.2 --webapp`.

### 1.1 Two entities with a one-to-many
- `Author` (id: uuid, name)
- `Book` (id: uuid, title, ManyToOne Author — **LAZY**, which is correct/default)

```bash
php bin/console make:entity Author    # name: string
php bin/console make:entity Book      # title: string; author: relation -> Author (ManyToOne)
php bin/console make:migration
php bin/console doctrine:migrations:migrate -n
```

### 1.2 Seed enough rows that N+1 is visible
A quick fixture command or `DoctrineFixtures`: ~50 authors, ~500 books. Enough that
"1 query for books + N queries for each book's author" shows clearly in the profiler.

### 1.3 The deliberately bad endpoint
```php
#[Route('/books', methods: ['GET'])]
public function list(BookRepository $books): JsonResponse
{
    $data = [];
    foreach ($books->findAll() as $book) {
        $data[] = [
            'title'  => $book->getTitle(),
            'author' => $book->getAuthor()->getName(), // ← lazy load PER book = N+1
        ];
    }
    return $this->json($data);
}
```

### 1.4 Confirm the N+1 exists
```bash
curl -s http://localhost:8000/books > /dev/null
vendor/bin/mate mcp:tools:call symfony-profiler-latest '{}'
```
✅ **Checkpoint D:** profiler shows a high query count on `/books` (~501 queries).
That's the symptom Mate will hand the agent.

---

## Stage 2 — Baseline: agent WITHOUT the skill (~30 min)

In Claude Code (Mate connected, **no `doctrine-query-optimization` skill installed**):

> Prompt: *"The /books endpoint is slow. Use the Mate profiler tools to find out
> why, then fix it."*

**Capture verbatim:**
- Did it call the profiler (vs. crawl files blindly)?
- Did it correctly identify the N+1?
- What fix did it propose? (Often: eager fetch everywhere, or `findAll()` kept,
  or a partial fix — note exactly.)
- Re-hit `/books`, record the new query count.

Save this as `baseline.md`. This is your control.

---

## Stage 3 — With the skill: detect (Mate) + remediate (skill) (~30–60 min)

### 3.1 Install the skill into the app
```bash
mkdir -p .claude/skills
cp -r /path/to/symfony-skills/skills/doctrine-query-optimization .claude/skills/
cp -r /path/to/symfony-skills/skills/doctrine-orm              .claude/skills/   # optional support
```
Confirm load: `/skills` inside Claude Code.

### 3.2 Same prompt, fresh session
> Prompt (identical to baseline): *"The /books endpoint is slow. Use the Mate
> profiler tools to find out why, then fix it."*

**Capture verbatim:**
- Profiler call → identifies N+1 (Mate's half).
- Fix proposed — expect it to now match the skill: `JOIN FETCH` / `addSelect`,
  a read-model DTO via DQL `NEW`, or `Paginator(fetchJoinCollection: true)` if paginating
  (the skill's half).
- Re-hit `/books`, record the new query count (should drop to ~1–2).

Save as `with-skill.md`.

---

## Stage 4 — Write up the result (~1–2 h) — the actual deliverable

`poc/RESULTS.md`, short and falsifiable:

| | Profiler used? | N+1 found? | Fix quality | Queries after |
|---|---|---|---|---|
| Without skill | ? | ? | ? | ? |
| With skill | ? | ? | ? | ? |

Then 3–4 sentences on the takeaway:
- Mate alone = detection, fix quality depends on the model's instincts.
- Skill alone = knows the fix, but no runtime signal to know it's needed here.
- Together = detect + remediate. (Or: where it fell short — equally publishable.)

Include: the bad endpoint, the agent's good fix, before/after query counts, and any
**wiring friction** you hit in Stage 0.5 (Johannes cares about exactly that).

---

## Stage 5 — Share

- Slack reply to Johannes with `RESULTS.md` + repo link to this runbook.
- Optional: the repurposed Twitter / r/PHP angle ("Mate finds it, a skill fixes it").
- If wiring was rough: that's a concrete, useful issue/PR direction for `symfony/ai`.

---

## Time budget & sequencing

| Stage | Est. | Risk |
|---|---|---|
| 0 Smoke test | 0.5–1 h | **Amber — do first** |
| 1 N+1 app | 1–2 h | Low |
| 2 Baseline | 0.5 h | Low |
| 3 With skill | 0.5–1 h | Low |
| 4 Write-up | 1–2 h | Low |
| **Total** | **~6–9 h (3 evenings + buffer)** | |

**Golden rule:** if Stage 0 Checkpoint C fails, STOP and report the friction. Don't
build the pretty demo on top of a broken handshake. The blocker is itself a finding.
