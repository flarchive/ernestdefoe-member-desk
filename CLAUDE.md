# Member Desk: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`).
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Every change goes to the Command Center (`local_f246f111-…`) for review first. dev + showcase until Ernest has tested; after release it goes on ALL live sites (Ernest, 2026-11-13).
- Scope and Ernest's decisions: `~/github/command-center/scopes/members.md`. Mockup: https://claude.ai/artifact/4EtG3sr1DaL6bp38SmWk1z

## Design rules that exist for a reason

- **Never re-implement erasure.** Anonymize/delete go through core's `DELETE /api/users/{id}`, which flarum/gdpr replaces (`gdprMode` in the body). 🚨 gdpr's endpoint ignores its own settings and its queued job enforces them (deletion is OFF by default), so `Access::gdprAllows()` only offers allowed modes.
- 🚨 **`JsonApi::process()` never loads the model or checks permissions for update/delete.** `Actions::call()` sends a real request through `JsonApi::handle()` with a path relative to the API (`/users/{id}`), so core's `can` checks and every extension's hooks run.
- **Log before acting.** `Recorder::record()` writes the "as they were" snapshot first; erased members keep email/IPs only as HMAC-SHA256 hashes salted with `ernestdefoe-member-desk.salt`; `member-desk:prune` strips names and hashes after `retention_days` (90). Erasing a member scrubs names from their earlier entries.
- **Gates leak if answered.** Email search/sort need viewEmails; IP search and the banned filter need viewIps; last-seen filters/sort need `user.viewLastSeenAt`. A filter the actor can't use is ignored.
- **Bans only through fof/ban-ips' API** (`BanIps`, via `ApiCall`), never its table: its banIP check, validation and hooks decide. A mod without banIP still marks spammers; their IPs just stay unbanned (`banSkipped`).
- **The log is filtered per viewer** (`Log::present(entry, viewer)`): last-seen, hidden groups and the suspend reason follow the viewer's own permissions, like the list.
- **Reports** (`Reports`): Docket (ernestdefoe/docket) first, else flarum/flags counts, else hidden; always through the owner's `whereVisibleTo`, in the column (two correlated subselects on the page query, no extra round trip), the filters and the tab. The list is 6 queries with Docket + tags (tags' permission lookup for Docket's scope), constant in rows.
- **Spammer is restorable.** Its entry's `after` lists exactly the posts, discussions and IPs it hid/banned; `unspam` undoes only those. Never bans the actor's own IPs or an IP an active member also uses.
- **List = fixed queries** (5 at 10/50/100 rows, admin and mod). Member page = fixed (23). Keep it that way: no per-row lookups.
- **Admin-only on install** (Ernest, 2026-11-14): the migration grants no group anything; owners opt moderators in. The showcase stays admin-only (real members); live sites start admin-only.
- Hard rules (`Access::PROTECTED`): never yourself, never an admin, for suspend/spammer/anonymize/delete/sign-out.
- extend.php: string literals only.

## Testing

- `composer test:setup` (SQLite: `touch /tmp/md_test.sqlite`, `DB_DRIVER=sqlite DB_DATABASE=/tmp/md_test.sqlite`) `&& composer test`, then `~/github/scopes/member-desk/dbtests.sh` (MySQL `dev_` + PostgreSQL `t_` in Docker; prints the driver and prefix). `composer analyse:phpstan`.
- Browser checks: `~/github/scopes/member-desk/shots.mjs` (admin|forum, light|dark), `measure.mjs` (table fit at 390–1920).
- Local forum `~/Herd/deskdev` (https://deskdev.test, MySQL `deskdev`, prefix `md_`; admin login in `~/Herd/deskdev-admin.txt`, API key in `~/Herd/deskdev-apikey.txt`). Seed: `php ~/github/scopes/member-desk/seed.php` (300 fake members, spam ring on 198.51.100.23).
