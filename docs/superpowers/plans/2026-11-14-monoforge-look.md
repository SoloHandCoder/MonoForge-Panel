# MonoForge Look and Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give MonoForge its own violet identity: a sidebar shell, an animated homepage, branded sign-in pages, and no Pterodactyl branding or update path left.

**Architecture:** Edit the stock `classic` theme in place. `layout.app` becomes a sidebar shell that draws every area's navigation (the pattern in `packages/sdk/THEMES.md` § "A sidebar shell" and § "The shell knows the area"), and `layout.account`, `layout.server` and `layout.admin` become thin layouts. Colours come from the `.dark` tokens in `packages/sdk/panel.css`. Two small back-end changes stop Pterodactyl upgrades and version checks.

**Tech Stack:** React 19, TypeScript, Tailwind 4, TanStack Router/Query, Vitest + Testing Library, Laravel 13 / PHP 8.3+, Pest.

**Spec:** `docs/superpowers/specs/2026-11-14-monoforge-look-design.md`

## Global Constraints

- Dark only. Accent `#8b5cf6`, light `#a78bfa`, soft text `#c4b5fd`; page `#0e0d15`, sidebar `#0b0a11`, card `#17151f`, raised/border `#27233a`, hover row `#1a1726`, active item bg `#221c3a` text `#ddd3ff`, text `#ece9f7`, muted `#9d97b5`.
- Status: online green `#3ee07a` on `#14301f` text `#7ef0a6`; starting/stopping amber `#f59e0b` on `#33280f` text `#fcd34d`; offline grey `#6b6680` on `#2a2632` text `#a8a2ba`. Usage bars gradient `#7c3aed` → `#a78bfa`.
- Radius: cards ~18px, buttons/inputs ~12px, pills full.
- Name "MonoForge" everywhere (comes from `config('app.name')`, default already "MonoForge").
- Font: Inter, bundled (no Google Fonts request).
- Looping animation only on the homepage welcome area and the sign-in pages. Elsewhere only short fades/slides and hover transitions. Animate only `transform` and `opacity`.
- `prefers-reduced-motion: reduce` → no loops, no stagger, no hover lift; final states shown.
- Keep every extension slot rendering (`nav.items.before/after`, `account.navigation.*`, `server.navigation.*`, `panel.navigation.*`, `dashboard.*`) and keep `--brand-logo` / `model.brand.logoUrl` working.
- CI must stay green: `npm test`, `npm run api:check`, `npm run build:production`, and the PHP lint/test jobs (Pint, Rector, PHPStan level 10, `pest --type-coverage --min=100`).
- Commits: author is the user; every message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Reduced motion:** with `prefers-reduced-motion: reduce`, the homepage and login show no moving blocks, no shimmer, no stagger; cards are fully visible immediately. (Task 5 and Task 6 tests.)
2. **A subuser without restart or console permission:** the quick-action row hides Restart / Console instead of offering a button that fails. (Task 5 test.)
3. **No servers yet:** the homepage still shows the welcome area and the existing empty state, with tiles showing 0, not NaN or an error. (Task 5 test.)
4. **Phone width:** below `lg` the sidebar is a drawer behind a Menu button and closes after a link navigates. (Task 4 test.)
5. **Root admin "others' servers" view:** tiles are hidden there (they would describe other people's servers). (Task 5 test.)

---

## File map

| File | Responsibility |
| --- | --- |
| `app/Console/Commands/UpgradeCommand.php` | Refuses to run (Task 1) |
| `app/Services/Helpers/SoftwareVersionService.php`, `config/pterodactyl.php` | No CDN call unless a URL is configured (Task 1) |
| `packages/sdk/panel.css` | MonoForge tokens, Inter, motion utilities (Task 2) |
| `resources/scripts/themes/classic/views/brand/MonoForgeMark.tsx` | The mark (Task 3) |
| `resources/scripts/themes/classic/views/brand/FloatingBlocks.tsx` | Animated blocks background (Task 3) |
| `public/favicons/*`, `resources/views/templates/wrapper.blade.php` | Icons (Task 3) |
| `resources/scripts/components/elements/PageFooter.tsx`, `views/auth/AuthCard.tsx` | Credits (Task 3) |
| `resources/scripts/themes/classic/views/layout/AppLayout.tsx` | Sidebar shell (Task 4) |
| `resources/scripts/themes/classic/views/layout/SidebarNav.tsx` | Per-area sidebar navigation (Task 4) |
| `.../layout/AccountLayout.tsx`, `ServerLayout.tsx`, `AdminLayout.tsx` | Thin layouts (Task 4) |
| `.../dashboard/Welcome.tsx` | Greeting, stat tiles, blocks (Task 5) |
| `.../dashboard/ServerList.tsx`, `ServerCard*.tsx`, `ServerQuickActions.tsx` | Card grid and hover actions (Task 5) |
| `.../layout/AuthLayout.tsx`, `.../auth/AuthCard.tsx` | Sign-in look (Task 6) |

---

### Task 1: Stop Pterodactyl upgrades and version checks

**Files:**
- Modify: `app/Console/Commands/UpgradeCommand.php`
- Modify: `config/pterodactyl.php:95-98` (the `cdn` block)
- Modify: `app/Services/Helpers/SoftwareVersionService.php`
- Modify: `resources/scripts/components/admin/overview/OverviewVersionCard.tsx` (only if it shows "latest/outdated" wording that no longer makes sense)
- Test: `tests/Integration/Console/Commands/UpgradeCommandTest.php`, `tests/Unit/Services/Helpers/SoftwareVersionServiceTest.php`

**Interfaces:**
- Produces: `config('pterodactyl.cdn.url')` defaults to `env('PTERODACTYL_CDN_URL', '')`. `SoftwareVersionService::isLatestPanel(): bool` returns `true` when no version data is available; `getPanel(): string` returns `config('app.version')` when there is no data.

- [ ] **Step 1: Write the failing tests**

```php
// UpgradeCommandTest.php — replace the existing tests
test('refuses to upgrade MonoForge from Pterodactyl releases', function (): void {
    Http::fake();
    $this->artisan('p:upgrade', ['--no-interaction' => true])
        ->expectsOutputToContain('MonoForge is updated from its own repository')
        ->assertExitCode(1);
    Http::assertNothingSent();
});

// SoftwareVersionServiceTest.php — add
test('makes no request and reports the panel as current when no CDN URL is set', function (): void {
    config(['pterodactyl.cdn.url' => '']);
    // build the service with a Guzzle client mock that fails the test if called
    expect($service->isLatestPanel())->toBeTrue()
        ->and($service->getPanel())->toBe(config('app.version'));
});
```

- [ ] **Step 2: Run them and see them fail**

Run: `vendor/bin/pest tests/Unit/Services/Helpers/SoftwareVersionServiceTest.php` and `vendor/bin/pest tests/Integration/Console/Commands/UpgradeCommandTest.php` (the second needs a database; run it in CI or the cloud environment)
Expected: FAIL.

- [ ] **Step 3: Implement**

`UpgradeCommand::handle()` prints "MonoForge is updated from its own repository (github.com/SoloHandCoder/MonoForge-Panel). Pull the latest code and rebuild instead." and returns `self::FAILURE`; remove the now-dead download code and options. In `SoftwareVersionService::cacheVersionData()` return `[]` without a request when the URL is empty; adjust `getPanel()`/`isLatestPanel()` per Interfaces. Leave Discord/donations fallbacks pointing nowhere Pterodactyl-specific (return `''`) and hide those links in the admin overview card if empty.

- [ ] **Step 4: Run the tests, Pint, Rector and PHPStan**

Run: `vendor/bin/pest tests/Unit/Services/Helpers`, `vendor/bin/pint app config`, `vendor/bin/rector process app --dry-run`, `vendor/bin/phpstan analyse --memory-limit=3G`
Expected: PASS, no changes, no errors.

- [ ] **Step 5: Commit** — `git commit -m "Stop pulling Pterodactyl releases and version checks"`

---

### Task 2: Colours, font and motion tokens

**Files:**
- Modify: `packages/sdk/panel.css` (`:root` and `.dark` blocks, lines ~46–140)
- Modify: the classic theme manifest/registration so the default and only scheme is `dark` (find where classic declares `schemes`)
- Modify: `package.json` (add `@fontsource-variable/inter`)
- Modify: `resources/views/templates/wrapper.blade.php` (`theme-color` → `#0e0d15`)
- Test: `resources/scripts/themes/monoforgeTokens.spec.ts` (new)

**Interfaces:**
- Produces CSS custom properties used by later tasks: `--status-online`, `--status-online-bg`, `--status-online-fg`, `--status-busy`, `--status-busy-bg`, `--status-busy-fg`, `--status-offline`, `--status-offline-bg`, `--status-offline-fg`, `--usage-from`, `--usage-to`, `--active-item`, `--active-item-foreground`, `--hover-row`; utility classes `animate-rise` (fade+12px up, 0.6s, `cubic-bezier(.2,.8,.2,1)`, honours a `--rise-delay`), `animate-fade-in` (0.2s), and a global `@media (prefers-reduced-motion: reduce)` rule disabling animations.

- [ ] **Step 1: Write the failing test** — reads `packages/sdk/panel.css` as text and asserts the `.dark` block sets `--primary: #8b5cf6`, `--background: #0e0d15`, `--card: #17151f`, `--sidebar: #0b0a11`, `--border: #27233a`, `--foreground: #ece9f7`, `--muted-foreground: #9d97b5`, `--success: #3ee07a`, `--status-busy: #f59e0b`, `--status-offline: #6b6680`; that `--font-sans` starts with `'Inter Variable'`; and that the file contains `prefers-reduced-motion: reduce`.
- [ ] **Step 2: Run** `npx vitest run resources/scripts/themes/monoforgeTokens.spec.ts` — Expected: FAIL.
- [ ] **Step 3: Implement** the tokens from Global Constraints in `.dark` (map the remaining roles sensibly: `--popover` `#1d1a29`, `--secondary`/`--muted` `#27233a`, `--accent` `#a78bfa`, `--ring` `#a78bfa`, `--primary-foreground` `#ffffff`, sidebar roles to the sidebar/active values), `--radius: 0.75rem` in `:root`, Inter via `@import '@fontsource-variable/inter'` and `--font-sans`/`--font-header`, and the motion utilities.
- [ ] **Step 4: Run** the spec, `npm run sdk:check`, `npm run tsc`, `npm run lint`, `npm test` — Expected: PASS (update any token snapshot in `packages/sdk` the change legitimately alters).
- [ ] **Step 5: Commit** — `git commit -m "Add the MonoForge violet palette, Inter and motion tokens"`

---

### Task 3: Mark, floating blocks, icons and credits

**Files:**
- Create: `resources/scripts/themes/classic/views/brand/MonoForgeMark.tsx`, `resources/scripts/themes/classic/views/brand/FloatingBlocks.tsx`
- Delete: `resources/scripts/themes/classic/views/auth/PterodactylMark.tsx` (update its importers)
- Modify: `resources/scripts/components/elements/PageFooter.tsx`, `resources/scripts/themes/classic/views/auth/AuthCard.tsx` (credit line only; the card's look is Task 6)
- Replace: `public/favicons/*` (keep the same filenames and sizes), add `public/favicons/favicon.svg`; link it in `wrapper.blade.php`
- Test: `resources/scripts/themes/classic/views/brand/brand.spec.tsx` (new)

**Interfaces:**
- Produces: `MonoForgeMark({ className?: string, title?: string }): JSX.Element` — inline SVG, `role="img"`, `aria-label` defaults to "MonoForge"; a rounded square filled with a `#8b5cf6`→`#c4b5fd` gradient and a bold geometric "M" in `#130c24`. `FloatingBlocks({ count?: number = 14, className?: string }): JSX.Element` — an `aria-hidden` absolutely-positioned layer of see-through violet squares (10–36px, border `rgba(196,181,253,.35)`, fill gradient `rgba(139,92,246,.35)`→`.05`) rising and rotating 180° over 8–18s each with randomised start offsets; positions derived from a fixed seed so renders are deterministic in tests; no animation under reduced motion.

- [ ] **Step 1: Write the failing tests** — `MonoForgeMark` renders an `img` role named "MonoForge"; `FloatingBlocks` renders `count` children inside an `aria-hidden="true"` container; `PageFooter` and `AuthCard` render no text matching `/Pterodactyl/` and `AuthCard` contains "MonoForge".
- [ ] **Step 2: Run** `npx vitest run resources/scripts/themes/classic/views/brand` — Expected: FAIL.
- [ ] **Step 3: Implement** the two components; credits become `© {year} MonoForge` (no external link). Render the PNG favicons from `favicon.svg` with any local tool; keep every existing filename.
- [ ] **Step 4: Run** the spec, `npm test`, `npm run lint`, `npm run tsc` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Add the MonoForge mark, floating blocks and icons"`

---

### Task 4: Sidebar shell

**Files:**
- Modify: `resources/scripts/themes/classic/views/layout/AppLayout.tsx` (becomes the shell)
- Create: `resources/scripts/themes/classic/views/layout/SidebarNav.tsx`
- Modify: `AccountLayout.tsx`, `ServerLayout.tsx`, `AdminLayout.tsx` → thin layouts (`<Main>{children}</Main>` only, keeping `className`); delete `AdminSidebar`
- Modify: `packages/sdk/slot-coverage.spec.ts` (`anchorFiles`: add `SidebarNav.tsx`)
- Test: `resources/scripts/views/layouts.spec.tsx` (update), `resources/scripts/themes/classic/views/layout/SidebarNav.spec.tsx` (new)

**Interfaces:**
- Consumes: `MonoForgeMark` (Task 3); tokens `--sidebar`, `--active-item`, `--active-item-foreground`, `--hover-row` (Task 2); `model.area` (`AppArea`), `model.links`, `model.brand`, `model.user`, `model.signOut`, `model.signingOut`; `useNavigation('account')`; `View name='app.search'`.
- Produces: `SidebarNav({ model }: { model: AppLayoutModel }): JSX.Element`. Structure by `model.area.kind`:
  - `dashboard` / `account`: **Servers** (the `dashboard` link; a count badge from the first page's pagination total of the account servers query once loaded), then **Account** and **Activity** from the account area's items, a **Developer** group label with **API keys** and **SSH keys** (match account items by route id; extension account items go after them in the same group), then an **Admin** group with **Admin area** only when `model.links` contains `admin`.
  - `server`: "← All servers" link to `/`, the server name with a status badge, then the server's items (plus `area.adminLink` when present).
  - `admin`: "← Back to panel" link to `/`, then the admin items.
  - Slots: `nav.items.before/after` around the main links; the area's `*.navigation.before/after` around the area items (as `AreaSlot` in THEMES.md).
  - Active item: `data-status="active"` → `bg-[var(--active-item)] text-[var(--active-item-foreground)]` and a 3px `#8b5cf6` left bar with a soft glow; hover → `--hover-row`.
  - Top: `MonoForgeMark` (24px) and `model.brand.name`, linking to `/`; when `model.brand.logoUrl` is set, that image replaces both. Under it, `<View name='app.search' />` styled as a sidebar item.
  - Bottom: avatar, username, "Administrator" when root admin, Sign out button.
  - Shell: `lg` and up a sticky `w-sidebar` aside on `--sidebar`; below `lg` a Menu button toggles it as a drawer that closes when `usePanelLocation().pathname` changes. The page sits in `<Main className='min-w-0 flex-1 animate-fade-in'>` keyed by pathname for a calm fade.

- [ ] **Step 1: Write the failing tests** (`SidebarNav.spec.tsx`, using the layout fixtures `accountArea`, `serverArea`, `adminArea` and a dashboard fixture):
  - dashboard: links named "Servers", "Account", "Activity", "API keys", "SSH keys" exist; "Admin area" only with an admin link in the model.
  - server area: a link "All servers" to `/`, the server's tab labels (e.g. "Console", "Files") and no "API keys".
  - admin area: a link "Back to panel" and the admin items.
  - active item carries `data-status="active"`.
  - every slot name above renders exactly once per area.
  - narrow screen: the Menu button toggles `aria-expanded` and the drawer closes after navigating (render with a router at `/`, click "Account", assert the drawer is closed).
- [ ] **Step 2: Run** `npx vitest run resources/scripts/themes/classic/views/layout resources/scripts/views/layouts.spec.tsx` — Expected: FAIL.
- [ ] **Step 3: Implement** the shell, `SidebarNav` and thin layouts; update `layouts.spec.tsx` expectations that assumed the top bar or per-area sub-navigation.
- [ ] **Step 4: Run** those specs, `npx vitest run packages/sdk`, `npm test`, `npm run api:check` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Move navigation into a MonoForge sidebar"`

---

### Task 5: Homepage — welcome, stat tiles and server cards

**Files:**
- Create: `resources/scripts/themes/classic/views/dashboard/Welcome.tsx`, `resources/scripts/themes/classic/views/dashboard/ServerQuickActions.tsx`
- Modify: `Dashboard.tsx`, `ServerList.tsx`, `ServerCard.tsx`, `ServerCardIdentity.tsx`, `ServerCardAddress.tsx`, `ServerCardMetrics.tsx`
- Test: `resources/scripts/views/dashboard.spec.tsx` (update), `resources/scripts/themes/classic/views/dashboard/homepage.spec.tsx` (new)

**Interfaces:**
- Consumes: `FloatingBlocks` (Task 3); tokens `--status-*`, `--usage-from/to`, `animate-rise` (Task 2); `ServerListModel` (`servers`, `totals.runningOnPage`, `totals.servers`, `scope`, `loading`), `ServerListItem` (`state`, `permissions`, `actions.restart`, `sending`, `identifier`), current user's username.
- Produces:
  - `welcomeStats(model: ServerListModel): { online: number; total: number; memoryUsed: number; memoryLimit: number }` (exported from `Welcome.tsx`): `online = totals.runningOnPage`, `total = servers.length`, memory = sum of `state.memory.value`/`limit` over items whose `state.kind === 'ready'`.
  - `Welcome({ model }: { model: ServerListModel })`: "Welcome back" label, heading `Hey {username}, your worlds are running.` with the username in a shimmering gradient span, tiles "servers online" (`{online} / {total}`, green number) and "memory in use" (formatted bytes `used / limit`, limit "∞" when 0); tiles hidden when `scope === 'others'`; `FloatingBlocks` behind, over `radial-gradient(120% 120% at 80% 0%, #241a44, #0e0d15 60%)`.
  - `ServerQuickActions({ item }: { item: ServerListItem })`: buttons **Console** (link to `/server/$id`, shown if `permissions.console`), **Restart** (shown if `permissions.restart`; opens a confirm dialog "Restart {name}? Players will be disconnected." and calls `item.actions.restart()` only on confirm; disabled while `item.sending`), **Files** (link to `/server/$id/files`).
  - Server cards render in a grid (1 column, 2 from `lg`), each with `animate-rise` and `--rise-delay = index * 0.08s` capped at 0.6s; hover lifts 4px with border `#4c3d86` and shadow `0 16px 36px -16px rgba(139,92,246,.6)`, and reveals `ServerQuickActions` (also revealed on keyboard focus within the card). Status pill: online green with pulsing dot, starting/stopping amber with faster pulse, offline/unavailable grey.
  - Keep every `dashboard.*` slot and the existing empty state, pagination and scope switch.

- [ ] **Step 1: Write the failing tests** (`homepage.spec.tsx`):
  - `welcomeStats` with two running (1 GiB/4 GiB, 2 GiB/8 GiB) and one offline server → `{ online: 2, total: 3, memoryUsed: 3 GiB, memoryLimit: 12 GiB }`; with zero servers → all zeros.
  - `Welcome` shows "Hey solohandcoder" and "2 / 3"; with `scope: 'others'` no tile text "servers online".
  - `ServerQuickActions` with `permissions.restart = false` has no "Restart" button; with `permissions.console = false` no "Console" link.
  - Clicking "Restart" opens the confirm dialog and does not call `actions.restart`; confirming calls it once.
  - Under `matchMedia('(prefers-reduced-motion: reduce)')` mocked true, cards are visible (no `opacity: 0` inline style) and `FloatingBlocks` sets no animation.
- [ ] **Step 2: Run** `npx vitest run resources/scripts/themes/classic/views/dashboard resources/scripts/views/dashboard.spec.tsx` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces; replace `ListRow` rows with the card grid.
- [ ] **Step 4: Run** those specs, `npm test`, `npm run api:check` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Build the MonoForge homepage with floating blocks and quick actions"`

---

### Task 6: Sign-in pages

**Files:**
- Modify: `resources/scripts/themes/classic/views/layout/AuthLayout.tsx`, `resources/scripts/themes/classic/views/auth/AuthCard.tsx`
- Test: `resources/scripts/themes/classic/views/auth/auth.spec.tsx` (new or extend the existing auth spec)

**Interfaces:**
- Consumes: `MonoForgeMark`, `FloatingBlocks` (Task 3).
- Produces: `AuthLayout` renders a full-height page with `FloatingBlocks` over the same radial background as the welcome area, and the page centred above it. `AuthCard` becomes a single centred card (`#17151f`, 18px radius, border `#27233a`) with the mark (48px) and "MonoForge" above the title, the form below, and the `© {year} MonoForge` line under it. Login, checkpoint, forgot- and reset-password pages all use it unchanged.

- [ ] **Step 1: Write the failing tests** — `AuthLayout` renders an `aria-hidden` blocks layer and its children; `AuthCard` renders the "MonoForge" mark and title and still submits the form on Enter.
- [ ] **Step 2: Run** `npx vitest run resources/scripts/themes/classic/views/auth` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** that spec, `npm test`, `npm run api:check`, `npm run build:production` — Expected: PASS.
- [ ] **Step 5: Commit** — `git commit -m "Give the sign-in pages the MonoForge look"`

---

### Task 7: Ship to panel.monoforge.net

**Files:** none in the repo.

- [ ] **Step 1: Push** `main` and wait for every CI job to pass (`gh run watch`).
- [ ] **Step 2: Build** locally: `npm run build:production`; then `git checkout -- packages/sdk` if the build regenerated SDK files.
- [ ] **Step 3: Back up** the live code: `sudo tar -czf /var/backups/monoforge-pre-look-$(date +%Y%m%d-%H%M%S).tar.gz -C /var/www monoforge --exclude=monoforge/vendor`.
- [ ] **Step 4: Update the server:** `git pull` in `~/monoforge-staging`, `composer install --no-dev --optimize-autoloader` there, upload `public/assets` and `public/favicons` from the PC into the staging copy, then `sudo rsync -a --delete --exclude=.env --exclude=storage/ --exclude=.git/ ~/monoforge-staging/ /var/www/monoforge/`, `sudo chown -R www-data:www-data /var/www/monoforge`, and as www-data `php artisan optimize:clear` and `php artisan migrate --force`; `sudo systemctl restart monoforge-queue`.
- [ ] **Step 5: Verify:** `https://panel.monoforge.net/auth/login` returns 200 with title "MonoForge" and no "Pterodactyl" in the HTML; `php artisan p:upgrade` exits 1 with the MonoForge message; both main nodes still answer `systemInformation()`. Ask the owner to sign in and check the homepage, a server's console and files, and the admin area.
