# Phase 1: Admin insights, announcements, notices, IP privacy, self-update, Discord

> For agentic workers: each task below is built by one agent in its own git worktree. Read your task and the Global Constraints; you will not see the other tasks' code until merge.

**Goal:** First batch of the owner's feature list for MonoForge Panel (Pterodactyl 2.0 fork, live at panel.monoforge.net, players with game servers).

**Owner's words for this phase:**
- "add a tab to the admin page called Storage and it shows the nodes that you have and what takes what storage and also then main website which has like the database and everything ... make it hide ip's from the nodes"
- "add a tab to the admin page for like seeing live how the servers are going like ram and cpu wise like 250%/2100% (100% is per thread) and remake the overview tab"
- "add a tab to the admin page called Announcements where i can make a error message or anything like a warning that the website's going down or something isn't working and more"
- "have in panel warnings" (for things like low token balance, server about to be suspended/deleted — the shop arrives in phase 3; this phase builds the notice system it will use)
- "if a node changes something and also an admin account if it changes something for someone it shows in the activity but hides my ip (every admin account)"
- "check for monoforge panel updates not pterodactyl ones and also have a auto update + migrate"
- "add somewhere that you can join the monoforge discord server" — invite `https://discord.gg/B3fd45pgvC`

## Global Constraints
- Look: dark violet MonoForge theme already in place (tokens in `packages/sdk/panel.css`: `--primary` #8b5cf6, `--status-online/busy/offline*`, `--active-item`, `--hover-row`, `animate-rise`, `animate-fade-in`). Use tokens and existing SDK primitives (`@/sdk`: Card, Button, Dialog, Badge, PageHeading, etc.), match the existing admin pages' structure (`resources/scripts/components/admin/*`). Calm motion only; respect reduced motion.
- Admin pages: register in `panelChildren` in `resources/scripts/router/routeTree.ts` with `staticData.nav` (label + lucide icon) and add the same item to the `adminNav` fixture in `resources/scripts/views/layouts.ts`. Admin API routes go in `routes/api-admin.php` (follow the neighbouring controllers in `app/Http/Controllers/Api/Admin/`, their resources, form requests and the OpenAPI attributes they use). Insert your routes/nav items at the position named in your task to keep merges simple.
- Client API routes in `routes/api-client.php`; models in `app/Models`; migrations dated `2026_11_15_*` with the exact numbers given per task.
- Never expose node IPs or admin IPs anywhere this phase touches.
- Tests: light. One focused spec/test file per feature (Pest for PHP, Vitest for TS) covering the main behaviour and permissions. The big test happens after all phases.
- CI must stay green: Pint, Rector, PHPStan level 10, `pest --type-coverage --min=100` (type every closure/param), `npm run tsc`, Vitest, oxfmt/oxlint, slot coverage, SDK type generation (`npm run sdk:check` scripts if you change view models or SDK exports).
- Name "MonoForge". Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Environment (Windows, Git Bash)
- PHP 8.4: `/c/Users/lilo5/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.NTS.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe` (php.ini beside it has the extensions). Unit tests without DB: `DB_HOST=UNIT_NO_DB php vendor/bin/pest --bootstrap vendor/autoload.php <path>`.
- Integration tests need MariaDB: start it once with `/c/Users/lilo5/tools/mariadb/mariadb-11.4.3-winx64/bin/mariadbd.exe --defaults-file='C:\Users\lilo5\tools\mariadb\data\my.ini' --bind-address=127.0.0.1` (background) if `mariadb.exe -uroot -h127.0.0.1 -e 'select 1'` fails, then run with `PATH=/c/Users/lilo5/tools/mariadb/mariadb-11.4.3-winx64/bin:$PATH DB_HOST=127.0.0.1 php vendor/bin/pest <path>`. Several agents share this one server: each worktree MUST use its own test database — set `DB_DATABASE=testing_<yourtask>` (e.g. `testing_a`) and create it first (`mariadb.exe -uroot -h127.0.0.1 -e 'create database if not exists testing_a'`).
- Lint only your changed files (CRLF checkout): `npx oxfmt <files>`, `npx oxfmt --check <files>`, `npx oxlint --type-aware <files>`; PHP: `php vendor/bin/pint <files>`, `php vendor/bin/rector process <files>` then pint again, `php vendor/bin/phpstan analyse --memory-limit=3G --error-format=raw <app files>`.
- node_modules, vendor and packages/sdk/testing-runtime in your worktree are junctions to the main checkout: never run npm install/composer install, never delete them. If you must add a dependency, report NEEDS_CONTEXT instead.
- If `npm run build:production` rewrites packages/sdk files with line-ending-only changes, revert them (`git diff --ignore-cr-at-eol --stat packages/sdk`).

---

### Task A: Announcements and in-panel notices
**Owns:** announcements + notices (backend, admin tab, client display).
- Migrations `2026_11_15_000001_create_announcements_table` (id, title, body text, level enum info|success|warning|danger, starts_at nullable, ends_at nullable, dismissible bool default true, timestamps) and `2026_11_15_000002_create_user_notices_table` (id, user_id FK cascade, level same enum, title, body, action_label nullable, action_url nullable, kind string nullable (e.g. `billing.low_balance`, unique per user+kind while unread), read_at nullable, timestamps).
- Models `Announcement` (scope `active()` = now within starts/ends) and `UserNotice` (scope `unread()`); a small service `Pterodactyl\Services\Notices\NoticeService` with `notify(User $user, string $level, string $title, string $body, ?string $kind = null, ?string $actionLabel = null, ?string $actionUrl = null): UserNotice` (upserts by kind while unread) — phase 3 billing will call this.
- Admin API: CRUD `/api/admin/announcements`. Admin tab **Announcements** (icon `megaphone`), inserted right after `activity` in panelChildren and adminNav: list with level badge and active/scheduled/ended status, create/edit dialog (title, body, level, optional schedule, dismissible), delete with confirm.
- Client API: `GET /api/client/notices` → active announcements + the user's unread notices; `POST /api/client/notices/{id}/read` (own notices only); announcements dismissal is client-side (localStorage per announcement id+updated_at, wrapped in try/catch).
- Display: a notice stack at the top of the page content inside the shell (`resources/scripts/themes/classic/views/layout/AppLayout.tsx`, above `<Main>` children, below nothing else) — coloured by level using tokens (danger red, warning amber, info violet, success green), dismiss button when dismissible, action button when set. Polls every 60s. Also on sign-in pages for announcements only (unauthenticated `GET /api/notices/public` returning active announcements) — render in `AuthLayout.tsx` above the card.
- Tests: active scope, admin CRUD permissions (non-admin 403), notices only own, kind upsert, banner renders levels and dismiss.

### Task B: Admin insights — Storage tab, Live usage tab, new Overview
**Owns:** three admin pages and one shared backend service.
- Service `Pterodactyl\Services\Insights\NodeUsageService`: per node, ask Wings for system info (`NodeClient::systemInformation()` → cpu_count, memory, disk if present) and each server's details (`ServerClient::details()` → state, utilization cpu_absolute/memory_bytes/disk_bytes); run per-node requests concurrently where practical (Laravel Http pool or sequential with short timeouts), cache results 5s, treat unreachable nodes as `offline` without failing the page. Never include IPs/FQDNs in its output — nodes are identified by id+name only.
- Admin API: `GET /api/admin/insights/usage` (per node: name, status online/offline, cpu used % vs capacity % = cpu_count*100, memory used/allocated/total, servers list with name, state, cpu %, cpu limit %, memory, disk) and `GET /api/admin/insights/storage` (per node: disk used by servers, sum of allocated disk, node disk limit, top servers by disk; plus "Website": panel database size from information_schema for the panel DB, `storage/` folder size, local backups size if any). No IPs.
- Admin tab **Live usage** (icon `activity`) after `nodes`: per-node cards with CPU like `250% / 2100%` and memory bars, auto-refresh every 5s (pause when tab hidden), expandable server rows sorted by CPU, offline nodes greyed.
- Admin tab **Storage** (icon `hard-drive`) right after Live usage: per-node used/allocated/limit bars with top servers; a "Website" card (database, storage folder, backups).
- **Remake Overview** (`components/admin/overview/OverviewContainer.tsx`): stat tiles (users, servers online/total, nodes online/total, CPU in use, RAM allocated/capacity, disk allocated/capacity), a compact live node strip (reuse the usage endpoint), latest activity (5 entries, no IPs), quick links (Announcements, Live usage, Storage, Settings), keep the version/update card (Task E will feed it real MonoForge update data; keep `OverviewVersionCard` in place and don't change its props). Remove the old Documentation/GitHub buttons row only if it duplicates; keep a single "MonoForge on GitHub" link.
- Tests: service maps Wings data and survives an offline node (Http::fake), no IP/fqdn keys in API output, admin-only.

### Task C: IP privacy in activity logs
**Owns:** how activity log IPs are exposed.
- Rule: an activity entry's IP is hidden (`ip: null` in API output, shown as "Hidden" in the UI) when the actor is a root admin, or when there is no user actor (system/node/Wings-triggered events). Applies to every non-admin-facing activity view: account activity, server activity (owners and subusers), and anything else in `app/Http/Resources/Api/Client` that serialises activity. Admin area activity (`app/Http/Resources/Api/Admin/ActivityLogResource.php`) also hides root-admin and system IPs (owner: "hides my ip (every admin account)").
- Implement once (e.g. a method on `ActivityLog` such as `visibleIp(): ?string`) and use it in every resource that outputs `ip`; check `resources/scripts` views that render IPs show "Hidden" for null.
- Also ensure node IPs/FQDNs are not shown to non-admins anywhere in activity metadata properties (scrub keys `ip`, `fqdn`, `node_ip` from properties for non-admin output if present).
- Tests: admin actor → null ip in client resource; normal user actor → ip shown; system actor → null; admin resource hides admin ip.

### Task D: MonoForge self-update and auto-migrate
**Owns:** release builds, update checking, updating.
- CI: add a workflow `.github/workflows/monoforge-release.yaml` that, on push to `main`, builds a release tarball: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build:production`, packs the panel (exclude .git, node_modules, tests, .github, storage contents except skeleton, .env*) as `monoforge-panel.tar.gz` with a `monoforge-panel.tar.gz.sha256`, and publishes/updates a GitHub release tagged `build-<short sha>` (mark as latest) with a `version.json` asset `{ "version": "<short sha>", "commit": "<sha>", "built_at": "<iso>" }`. Use `gh release create` with the workflow `GITHUB_TOKEN` (`permissions: contents: write`). Keep older releases (prune to last 10).
- Panel: config `monoforge.update` (repo `SoloHandCoder/MonoForge-Panel`, channel `main`, mode from setting: `off|notify|auto`, default `notify`). The running build's identity comes from a `version.json` at the panel root written by the workflow into the tarball (fallback: `config('app.version')`). `MonoForgeUpdateService`: `latest()` via GitHub API `/repos/{repo}/releases/latest` (cached 30 min, timeout 5s, failures → null), `isUpdateAvailable()`, `apply()`: download tarball + sha256 over HTTPS from the release assets only, verify checksum, put the panel in maintenance (`php artisan down` with a secret-free maintenance page), back up current code to `storage/app/updates/backup-<time>.tar.gz` (exclude storage, vendor optional) and the database with `mysqldump` if available, extract over the panel dir keeping `.env` and `storage/`, run `migrate --force`, `optimize:clear`, `queue:restart`, `up`; on any failure restore the code backup and `up`, and record the error.
- Replace the disabled Pterodactyl check: `SoftwareVersionService` / the admin version endpoint report MonoForge's current/latest builds, `OverviewVersionCard` shows "MonoForge build <sha> — up to date" or "Update available (<sha>) — Update now" (button → `POST /api/admin/updates/apply`, confirm dialog, progress via polling `GET /api/admin/updates/status`).
- Command `p:monoforge:update {--check} {--force}` and schedule it hourly (in the panel's scheduler) when mode is `auto`, daily check when `notify`. Settings: add the mode selector to the admin Settings page (existing settings mechanism).
- Note in your report how the live server (files owned by www-data, panel runs as www-data, no git checkout) can run this — the updater must run as www-data via the scheduler/queue, never require root.
- Tests: version comparison, checksum mismatch aborts without touching files (use a temp dir), API permission, command `--check` output with Http::fake.

### Task E: Discord link
**Owns:** the Discord invite placements.
- `https://discord.gg/B3fd45pgvC`, opens in a new tab with `rel="noopener noreferrer"`.
- Sidebar (`resources/scripts/themes/classic/views/layout/SidebarNav.tsx`): a "Join our Discord" item near the bottom above the user block, with a Discord-style icon (lucide `message-circle` or an inline Discord glyph SVG in `#5865F2`), styled like other sidebar items.
- Sign-in card footer (`resources/scripts/themes/classic/views/auth/AuthCard.tsx`): small "Join our Discord" link next to the copyright.
- Homepage welcome area (`resources/scripts/themes/classic/views/dashboard/Welcome.tsx`): a small secondary "Join the Discord" button.
- Put the URL in one constant (e.g. `resources/scripts/themes/classic/views/brand/links.ts`).
- Tests: link present with correct href/target/rel in sidebar and auth card.
