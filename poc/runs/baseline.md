# Run A — Baseline (Mate connected, NO doctrine-query-optimization skill)

Prompt: "The /books endpoint feels slow. Use the Mate profiler tools to find out why, then fix it."
Model: claude-opus-4-8

## What the agent did
1. Called `symfony-profiler-list` (url=/books) → picked latest token.
2. `symfony-profiler-get` + read `time` and `db` collectors via MCP resources.
3. Diagnosed N+1 from db collector: query_count 51 = 1×(SELECT FROM book) + 50×(SELECT FROM author WHERE id=?).
4. Found the controller, added `BookRepository::findAllWithAuthor()` using
   `->addSelect('author')->join('book.author','author')`, switched controller to it.
5. Re-hit /books, re-read profiler db collector to verify.

## Result
| Metric | Before | After |
|---|---|---|
| Query count | 51 | 1 |
| DB time | 8.89 ms | 3.61 ms |

## Assessment
- Profiler used: YES (correctly, triage→detail).
- N+1 identified: YES.
- Fix quality: HIGH — JOIN FETCH via addSelect, the canonical fix. Essentially what the
  skill would prescribe.
- Takeaway: for a clean textbook N+1, model + Mate runtime signal alone was sufficient.
  This is the control; Run B (with skill) tests whether the skill changes anything here.
