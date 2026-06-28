# Mate PoC — Friction Log (for Johannes / symfony/ai)

Setup: DDEV (PHP 8.3, MariaDB) · Symfony 7.4 webapp · Mate from
`wachterjohannes/symfony-ai@mate-skills-install` (PR #2213) · installed via Composer
**path repos** (branch, not released). Each item below is reproducible.

## 1. Monorepo dev-branch install is awkward via Composer
- A `vcs` repo pointed at the fork root only exposes `symfony/ai` (the root package),
  not the `symfony/ai-mate` subpackage — so `require symfony/ai-mate:dev-<branch>` fails
  with "found ... but it does not match the constraint."
- Worked around with a **path repo** at `.mate-src/src/mate` (cloned fork inside the
  project mount so the DDEV container can see it), `symlink:false`.

## 2. Extension ↔ mate version pin fights the dev branch
- `symfony/ai-symfony-mate-extension` pins `symfony/ai-mate: ^0.10`, but the path-repo
  mate is `dev-mate-skills-install`, which does NOT satisfy `^0.10`.
- Composer then tries to pull the extension from Packagist (which pins to Packagist mate),
  producing an unresolvable set.
- Worked around with an inline alias: `require "symfony/ai-mate:dev-mate-skills-install as 0.10.99" -W`.
- **Suggestion:** for unreleased branches, a looser/self constraint or a documented
  "install the whole branch" path would help. This will bite anyone packaging a Mate
  extension against an unreleased mate (e.g. a third-party convention-skills extension).

## 3. Profiler tools crash on lazy-proxy generation  ← real bug
- `mate mcp:tools:call symfony-profiler-list '{}'` errored:
  `Cannot generate lazy proxy for service "...Profiler\Service\Formatter\RequestCollectorFormatter".`
- Cause: the 8 collector formatters in `src/Bridge/Symfony/config/config.php` are declared
  `->lazy()`, but lazy-proxy generation fails inside Mate's standalone container
  (symfony/var-exporter v7.4.9 IS present — so not a missing dep; the standalone container
  likely lacks the proxy dumper config, or the formatter classes aren't proxyable there).
- **Workaround applied locally for the PoC:** removed `->lazy()` from those 8 services.
  After that, `symfony-profiler-list` returns real profiles. (Patched the vendor copies +
  the path source; `.bak` files kept.)
- **This is worth a real issue/PR on symfony/ai.** Either drop `->lazy()` or make the
  standalone container support proxy generation.

## 4. Generated .mcp.json doesn't work under DDEV/Docker  ← adoption gap
- `mate init` writes `.mcp.json` with `command: ./vendor/bin/mate`, `args: [serve,
  --force-keep-alive]`. That assumes PHP on the HOST.
- Under DDEV (and any Docker-based setup), PHP lives only in the container — the host has
  no `php`. Claude Code (host) launches `./vendor/bin/mate serve`, which fails:
  `/mcp` shows `symfony-ai-mate · ✘ failed`.
- This affects the large share of Symfony devs on DDEV/Docker — Mate is unusable from a
  host Claude Code session out of the box.
- **Workaround:** rewrite `.mcp.json` command to `ddev` with
  `args: ["exec","vendor/bin/mate","serve","--force-keep-alive"]` so the host launches
  Mate inside the container (stdio forwarded by `ddev exec`).
- **Suggestion:** `mate init` could detect DDEV/Docker (e.g. `.ddev/` present) and emit a
  container-aware command, or document the wrapper.

## Status
- Gate A (Mate lists profiler/service tools): PASS
- Gate B (Mate returns real profile JSON from the running app): PASS (after fix #3)
- Gate C (Claude Code auto-discovers Mate via generated .mcp.json): host-as-is FAILED
  (finding #4); PASS after `ddev exec` wrapper — agent called symfony-profiler-list and
  reported 404 for GET / through the full host→container→Mate→profiler loop.
- Gate D (deliberate N+1 visible via Mate): PASS — GET /books = **51 DB queries**
  (1 findAll + 50 per-author lazy loads; Doctrine dedupes within-request so 51, not 501).
  Profile token e.g. 9a28f9, db collector query_count:51. This is the baseline symptom.

## 5. symfony-skills installs through Mate's skills:install — WORKS ✅
- Added a minimal `composer.json` to fatonh/symfony-skills with
  `"type": "symfony-ai-mate"` + `"extra": {"ai-mate": {"skills": ["skills"]}}`.
- Installed it into mate-poc as a local **path repo** (the repo had to be copied INSIDE
  the project mount — same DDEV-boundary issue as the mate source; siblings outside
  `/var/www/html` aren't visible to the container).
- On `composer require`, Mate's composer plugin auto-detected it:
  "AI Mate: 3 extensions synchronized (1 new). + fatonh/symfony-skills".
- `mate skills:install` symlinked all 16 skills into `.claude/skills/` with the `mate-`
  prefix (e.g. `mate-doctrine-query-optimization`), frontmatter intact, symlink mode so
  they auto-update with the package.
- **Conclusion (Q2a — mechanism): a third-party Symfony convention-skills repo installs
  cleanly through PR #2213's skills:install.** Only friction: path-repo siblings must be
  inside the DDEV mount (see finding #1/#4 — same root cause).

## PoC baseline (the control)
- Endpoint: `GET /books` (src/Controller/BookController.php) — lazy getAuthor() in a loop.
- Data: 50 authors, 500 books.
- **Bad query count: 51** (via Mate profiler db collector).
- Target after fix: ~2 (one JOIN-fetched query, or a DQL read model).
