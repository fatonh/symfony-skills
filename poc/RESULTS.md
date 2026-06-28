# PoC Results — Symfony Mate + convention-skills (detect + remediate)

**Setup:** DDEV (PHP 8.3, MariaDB 11.8) · Symfony 7.4 webapp · Doctrine ORM 3.6 ·
Symfony AI Mate from PR #2213 (`wachterjohannes/symfony-ai@mate-skills-install`) ·
skill repo `fatonh/symfony-skills` installed via Mate's `skills:install`.

This PoC tested two separate questions. Keep them separate — they're different claims.

---

## Q1 — Does the integration work at all?  → YES (proven)

Full loop verified host → DDEV container → Mate → Symfony profiler → Claude Code agent.

| Gate | What it proves | Result |
|---|---|---|
| A | Mate lists profiler/service tools | ✅ |
| B | Mate returns real profile JSON from the running app | ✅ |
| C | Claude Code auto-connects to Mate and the agent calls a Mate tool | ✅ (after DDEV fix, see friction #4) |
| D | A deliberate N+1 is visible through Mate (51 queries on /books) | ✅ |

## Q2a — Can a third-party Symfony skills repo install through Mate?  → YES (proven)

- Added a minimal `composer.json` to `fatonh/symfony-skills`:
  `"type": "symfony-ai-mate"`, `"extra": {"ai-mate": {"skills": ["skills"]}}`.
- `composer require` → Mate's composer plugin auto-detected it
  ("AI Mate: extensions synchronized (1 new). + fatonh/symfony-skills").
- `mate skills:install` symlinked **all 16 skills** into `.claude/skills/` (`mate-` prefix),
  frontmatter intact. PR #2213's mechanism consumed a real external convention-skills repo.

## Q2b — Does the skill change the agent's fix for a textbook N+1?  → NO (and that's a real finding)

Same endpoint, same prompt, model = claude-opus-4-8. (Full transcripts in `poc-results/`.)

| | Profiler used | N+1 found | Fix | Queries after |
|---|---|---|---|---|
| **Run A — no skill** | ✅ | ✅ | `addSelect`+`join` repo method | 51 → 1 |
| **Run B — skill via Mate** | ✅ | ✅ | **identical** (skill explicitly cited) | 51 → 1 |

The skill loaded and was cited ("the Mate query-optimization skill is directly relevant
here"), but produced the **same** canonical fix the model already reached unaided. For a
textbook N+1, **Mate's runtime signal is the decisive context** — which supports the
"better context, not more context" thesis. The skill's added value would show on cases
where naive instinct is *wrong* (JOIN-fetch + pagination LIMIT, keyset, read-model DTOs) —
deliberately out of scope here, and the obvious next experiment.

---

## Friction found (the most useful output for the maintainer)

See `FRICTION-LOG.md` for repro detail. Summary:

1. **Monorepo dev-branch install is awkward** — a `vcs` repo on the fork root only exposes
   `symfony/ai`, not the `symfony/ai-mate` subpackage; needed path repos.
2. **Extension ↔ mate version pin** — `ai-symfony-mate-extension` pins `ai-mate: ^0.10`,
   which a `dev-<branch>` mate doesn't satisfy; needed an inline `as 0.10.99` alias.
3. **Profiler tools crash on lazy-proxy generation** — `Cannot generate lazy proxy for
   service ...RequestCollectorFormatter`. The 8 collector formatters are `->lazy()`, which
   fails in Mate's standalone container (var-exporter present, so not a missing dep).
   Workaround: drop `->lazy()`. **Worth a real fix/issue.**
4. **Generated `.mcp.json` doesn't work under DDEV/Docker** — it uses
   `command: ./vendor/bin/mate`, assuming host PHP. DDEV users have no host PHP, so
   Claude Code shows `symfony-ai-mate ✘ failed`. Workaround: wrap in
   `ddev exec`. Affects most Symfony devs. **Suggestion: detect DDEV/Docker in `mate init`.**
5. **Skill-repo install boundary** — path-repo sources must live inside the DDEV mount
   (same root cause as #1/#4).

## Bottom line
The detect (Mate) + install (skills:install) plumbing works end-to-end with a real
third-party Symfony skills repo. For an easy N+1 the skill didn't change the outcome —
the runtime signal did. The high-value next step is a scenario where instinct fails, to
isolate the skill's contribution. Most actionable now: friction items #3 and #4.
