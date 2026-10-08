---
name: start-work
description: Start any new feature, fix, refactor or docs change in this IPTV service on its own branch, never on main. Use at the beginning of every task that will change files, before the first edit or commit. Pushing to main deploys to production, so main only receives finished work through the ship-to-main skill.
---

# Start work on a branch

`main` is production: every push to `main` runs the checks and, if they pass, deploys to the live server (`.github/workflows/ci.yml`, job *deploy*). So **nothing is ever committed or pushed to `main` directly.** All work happens on a branch and reaches `main` only through the `ship-to-main` skill, when it is 100% ready.

## Steps

1. **Check where you are.** `git status -sb` and `git branch --show-current`.
   - Uncommitted changes you didn't just make → stop and ask the user (don't stash or discard them).
   - Already on a work branch for this same task → just continue, skip to step 4.
   - On `main` → continue.
2. **Get the latest main.** `git fetch origin`.
3. **Create the branch from it**, never from an old local main:

```bash
git switch --no-track -c <type>/<short-kebab-name> origin/main
```

   `--no-track` matters: without it git makes the branch track `origin/main`, and a plain `git push` would then push to production. Push with `git push -u origin <branch>` (the first push sets the right upstream).

   Types: `feature/` (new behaviour), `fix/` (bug), `docs/`, `chore/` (tooling, deps, CI), `refactor/`. Names say what, not when: `feature/two-factor-recovery`, `fix/playlist-order`. One task = one branch; unrelated work gets its own branch, so each can be shipped or dropped alone.
4. **Work normally**, following `CLAUDE.md`. Commit in small steps on the branch with clear messages (commit freely; branches never deploy). Keep docs, `CHANGELOG.md` and `lang/mk.json` up to date as you go (`update-docs` skill), not at the end.
5. **Push the branch to back it up** when useful: `git push -u origin <branch>`. A branch push does not deploy and does not run the checks (they run on pull requests, see `ship-to-main`).
6. **Say what's next.** Tell the user the branch name and that it is not on `main`; when the work is finished, use `ship-to-main`. Don't merge or push to `main` because the work "looks done": the user decides when it ships.

## Rules

- Before any `git push`, check the target: `git rev-parse --abbrev-ref @{u}` must be your branch, never `origin/main`.
- Never `git commit`, `git push origin main`, `git merge ... main` + push, or force-push while on `main`.
- Never rewrite history that is already on `origin/main`.
- Hotfix for a broken production: still a branch (`fix/...`), still `ship-to-main`, just with the smallest possible change.
- A task that only reads or answers questions needs no branch.
