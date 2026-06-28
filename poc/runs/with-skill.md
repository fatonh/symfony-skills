# Run B — With skill (installed via Mate skills:install)

Prompt (identical to Run A): "The /books endpoint feels slow. Use the Mate profiler tools to find out why, then fix it."
Model: claude-opus-4-8
Skill: `mate-doctrine-query-optimization` (installed through Mate's skills:install, symlinked into .claude/skills/)

## What the agent did
1. Called `symfony-profiler-list` / `-get`, read `time` + `db` collectors via MCP.
2. Diagnosed N+1 from db collector (query_count 51 = 1×book + 50×author).
3. **Explicitly cited the skill:** "The Mate query-optimization skill is directly
   relevant here. Let me apply the idiomatic fix."
4. Added `BookRepository::findAllWithAuthor()` (`->addSelect('author')->join('book.author','author')`),
   switched controller to it.
5. Re-hit /books, re-read profiler to verify.

## Result
| Metric | Before | After |
|---|---|---|
| Query count | 51 | 1 |

## Comparison to Run A (no skill)
- **Fix: identical** — same JOIN-fetch repository method, same controller change.
- **Difference observed:** Run B explicitly referenced the skill before fixing; Run A
  reached the same fix from instinct + profiler alone.
- **Outcome difference: none** (both 51 → 1).

## Honest interpretation
For a textbook N+1, a strong model + Mate's runtime profiler signal already produces the
canonical fix. The skill loaded and was consulted, but did not change the outcome here —
the runtime signal was the decisive context. This supports the "better context, not more
context" thesis: detection (Mate) carries the easy cases.

Where the skill would be expected to differentiate (NOT tested in this PoC, scoped to a
single N+1): cases where naive instinct is wrong — e.g. JOIN-fetch + pagination LIMIT
(needs Paginator(fetchJoinCollection) or a DQL read-model), keyset pagination, or
read-model DTOs over full-entity hydration. That's the natural follow-up experiment.
