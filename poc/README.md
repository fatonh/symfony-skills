# PoC — Symfony Mate + these skills (detect + remediate)

A small experiment run against [Symfony AI Mate PR #2213](https://github.com/symfony/ai/pull/2213)
(`skills:install`): can Mate's runtime profiler *detect* a problem and these convention
skills *remediate* it, and can this repo install through Mate as an extension?

**Start here → [RESULTS.md](RESULTS.md).**

| File | What |
|---|---|
| [RESULTS.md](RESULTS.md) | The findings: integration works, skills install via Mate, honest A/B on a textbook N+1. |
| [FRICTION-LOG.md](FRICTION-LOG.md) | 5 reproducible rough edges hit on the branch (incl. a lazy-proxy crash and a DDEV `.mcp.json` failure). |
| [runs/baseline.md](runs/baseline.md) | Agent run WITHOUT the skill (control). |
| [runs/with-skill.md](runs/with-skill.md) | Agent run WITH the skill installed via `skills:install`. |
| [mate-skills-poc.md](mate-skills-poc.md) | The original step-by-step plan. |

**Headline:** the detect (Mate) + install (`skills:install`) plumbing works end-to-end with
this repo as a real third-party extension. For an easy N+1 the skill didn't change the
outcome — the runtime signal did — which supports Mate's "better context, not more context"
framing. Bench: DDEV · PHP 8.3 · Symfony 7.4 · Doctrine ORM 3.6.
