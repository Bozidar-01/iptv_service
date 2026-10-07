---
name: update-docs
description: Update the project documentation in docs/ and the CHANGELOG after any code change in this IPTV service. Use after implementing a feature, fixing a bug, changing the schema/routes/services, or when the user asks to document something. Required at the end of every task that changes behaviour.
---

# Update docs

Docs are the source of truth for what the IPTV service does. Every behaviour change ships with a docs change in the same task.

## Steps

1. **Work out what changed.** Re-read the diff or the files you edited. Classify it:
   - schema (migration/model) → `docs/data-model.md`
   - route added/removed/renamed → `docs/routes.md`
   - admin UI / CRUD → `docs/features/admin-panel.md`
   - login/auth → `docs/features/authentication.md`
   - `/m3u`, `/play`, tokens, sessions, limits, logging → `docs/features/playlist-and-streaming.md`
   - new service, layer or convention → `docs/architecture.md` (and `CLAUDE.md` if future work should follow it)
   - setup/commands/seeders/env vars → `docs/getting-started.md`
   - new feature area → new file in `docs/features/`, linked from `docs/README.md`
2. **Edit the affected docs.** Describe current behaviour, not history. Name files, routes, fields, status codes and limits exactly as they are in code. Don't describe anything you haven't verified in the code.
3. **Known issues.** If the change fixes something in `docs/known-issues.md`, delete that entry. If you noticed a problem you are not fixing, add it with the file and a suggested fix.
4. **CHANGELOG.** Add an entry at the top of `## Unreleased` in `docs/CHANGELOG.md`:
   ```
   ### YYYY-MM-DD — Short title
   - What changed and why (one line each).
   - Files: `path/a.php`, `path/b.blade.php`
   ```
   Use today's absolute date.
5. **Status line.** If a headline feature was added or removed, update the "Status" section in `docs/README.md`.
6. **CLAUDE.md.** Only update it for things every future session needs: new commands, conventions, or gotchas. Keep it short.

## Done when

- Every file in the diff is reflected in at least one doc or is purely internal (say so in the changelog line).
- The CHANGELOG has an entry.
- Links between docs still resolve.
