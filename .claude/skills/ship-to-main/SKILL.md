---
name: ship-to-main
description: Release finished work from a branch to main, which deploys to the live production server. Use only when the user says the work is ready to ship, merge or release, or asks "is this ready?". Runs the full readiness checklist, opens a pull request, waits for CI, asks for an explicit go, merges and verifies the deploy.
---

# Ship a branch to main (= deploy to production)

Merging into `main` puts the code on the live server within minutes: GitHub Actions run the checks, then the *deploy* job updates the server (database backup, `composer install`, `migrate --force`, caches, `/login` must answer 200). So a merge is a production release. Ship only when the work is **100% ready**, and only after the user says so **for this branch**: an earlier "yes" for another branch doesn't count.

## 1. Is it 100% ready? (all must be true; report each, don't skip)

Run from the work branch. Fix problems on the branch, then start the checklist again.

- [ ] Not on `main`; `git status` clean (everything committed).
- [ ] `composer check` is green (Pint, PHPStan, tests), or the Docker equivalents: `docker compose exec app vendor/bin/pint --test`, `… vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `… php artisan test`. No new entries in `phpstan-baseline.neon`.
- [ ] `npm run e2e` is green if anything in the panel's UI or JavaScript changed (needs Chrome; see `docs/getting-started.md`). `python3 relay/test_relay.py` if `relay/` or `ReceiverService::sign()` changed.
- [ ] New behaviour has tests; a fixed bug has a test that failed before the fix (`write-tests` skill).
- [ ] Docs match the code: `CHANGELOG.md` entry under *Unreleased*, feature docs, `data-model.md` / `routes.md` for schema and route changes, `known-issues.md` updated (`update-docs` skill). Every new user-visible string is in `lang/mk.json` (LocaleTest passes).
- [ ] UI changes checked in the browser: light and dark mode, 375 px wide (`run-app` skill).
- [ ] **Migrations** (if any) run up *and* down on MySQL, not only SQLite: `docker compose exec app php artisan migrate`, `migrate:rollback --step=N`, `migrate` again. They must be safe on a live database with real data (no long locks on big tables, nothing destructive without a backup path).
- [ ] **Server impact written down:** new or changed `.env` variables (the deploy never edits the server's `.env`: list every one the user must add by hand *before* merging, with the value to use and what happens if it is missing), new cron/queue needs, new packages or PHP extensions, anything that needs `nginx`/hosting changes.
- [ ] No debug leftovers, secrets, `.env` values, `dd()`, or large unrelated changes. Read your own diff: `git diff origin/main...HEAD`.
- [ ] Risky or large change → also run the `code-review` skill (and `security-review` for auth, secrets, proxy code) and fix what it finds.

If anything is not true, say so plainly and stop: "not ready: <what>". Don't open a PR for unfinished work.

## 2. Bring it up to date with main

```bash
git fetch origin && git rebase origin/main
```

If main moved, re-run the checks that could be affected (at least `composer check`). Resolve conflicts carefully; never resolve by discarding someone else's change. After a rebase push with `git push --force-with-lease origin <branch>` (only ever on the work branch).

## 3. Open the pull request

```bash
git push -u origin <branch>
gh pr create --base main --title "<what it does, in one line>" --body-file <file>
```

The description (write it to a file in the scratchpad) has: **What and why**, **How it was tested**, **Server impact** (migrations, env vars to add, anything manual), **How to go back**. Never put secrets in it.

## 4. Wait for the checks

```bash
gh pr checks --watch
```

All five must pass (tests, style + analysis, browser tests, MySQL migrations, relay). A red check is fixed on the branch, never bypassed: no `--admin`, no merging with failures, no disabling a check to get through.

## 5. Ask for the go-ahead (required)

Stop and ask the user, in plain words, something like: *"All checks are green. Merging deploys to production now: migrations: <yes/no>, new env vars: <none / list, already added?>. Merge `<branch>`?"* Wait for a clear yes. If `.env` changes are needed, confirm they are done on the server first.

## 6. Merge and watch the deploy

```bash
gh pr merge <pr> --squash --delete-branch
gh run watch $(gh run list --branch main --limit 1 --json databaseId -q '.[0].databaseId')
```

(If the `production` environment requires approval, tell the user to approve the deploy in GitHub → Actions.) Then verify and report: the deploy job's log shows `Done: <old> -> <new>` and `/login answers 200`; for UI changes also look at the live site. Finish locally: `git switch main && git pull --ff-only`, delete the local branch.

## If the deploy fails or the release is bad

- Deploy job red: its log says what stopped and how to go back; the database dump from just before is in `~/deploy-backups/` on the server. Don't re-run blindly: read the error, fix it on a new `fix/` branch, ship that.
- Code is bad but the site is up: `git switch -c fix/revert-<name> origin/main && git revert <merge-sha>`, then ship that branch (same checklist, smallest change). Migrations are not undone by a revert: use `php artisan migrate:rollback --step=N --force` on the server or the dump.
- Always tell the user what happened, what state production is in now, and what you propose.

## Never

- `git push origin main`, `git push --force` to `main`, or committing on `main`.
- Merging without green checks, without the user's go-ahead for this branch, or with known failures "to fix later".
- Editing the server by hand to make a deploy pass (that makes the next deploy stop on "files were changed on the server").
