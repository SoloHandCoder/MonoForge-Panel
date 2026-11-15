# Phase 6 — Stand-out features

> For agentic workers: environment, commands and commit rules are in `.superpowers/sdd/night/env.md`. Each task is on its own branch from `main`. Owner: "something the rest of the server hosts don't have".

## Global constraints
- MonoForge look and the existing components; mobile-friendly; accessible; reduced-motion respected.
- No IPs or node FQDNs anywhere public or player-facing (use `TunnelService::displayAddress`; without a tunnel, show nothing public).
- Every new endpoint has Scribe docs, the regenerated client and SDK, and a green OpenAPI contract test. No new `unknown` in generated types (give examples for empty arrays). Activity events get translations, with named properties (the translation test reads literal keys).
- Respect subuser permissions everywhere. Add new subuser permissions where a feature needs one, with translations.
- The CPU is weak: scheduled work is cheap, cached, `withoutOverlapping()->onOneServer()`.
- Tests for every behaviour; PHPStan level 10; tsc/oxlint/oxfmt clean.
- Shared helper (Task Z owns it; others create a stand-in with EXACTLY this shape if Z isn't merged, in one commit titled "stand-in"): `Pterodactyl\Services\Query\ServerQuery::status(Server $server): ?QueryStatus` with `final readonly class QueryStatus { public int $online; public int $max; public ?string $motd; public ?string $version; public array $players /* list<string>, may be empty */ }`. It returns null when the game doesn't answer or isn't supported. Minecraft Java uses Server List Ping to the allocation from the panel host (2 s timeout); results are cached 30 s per server.

## Task D — AI crash doctor
- When a server stops unexpectedly (Wings state goes from running/starting to offline without a stop or kill requested through the panel), or fails to start, the server page shows a banner: "Your server crashed. Let the AI doctor check it?"
  - Detection: the scheduler compares the cached resource state each minute, plus the console's own state events on the client side.
  - Store `server_crashes` (server, detected_at, last console lines (masked), status).
- The "Diagnose" button (also available manually in the console toolbar) starts an assistant conversation in a dedicated "crash doctor" mode:
  - the system prompt focuses on diagnosis;
  - it is pre-loaded with the masked last ~200 console lines and the newest file in `crash-reports/` and `logs/latest.log` tail, using the existing tools and masking;
  - it asks the model for: what happened, in plain language; the likely cause; and fixes, as tool calls (edit_file, update_startup_variable such as the Java version or docker image, delete a broken plugin jar, power start) that go through the normal Allow/Deny cards.
- Billing, free allowance and permissions exactly as the assistant (needs `assistant.use`, plus the tool permissions). If the assistant is off, there's no banner. Admin setting: crash doctor on/off.

## Task M — One-click plugins, mods and modpacks
- Browse and search **Modrinth** (no key) and **Hangar** (PaperMC, no key), plus **CurseForge** when the admin sets a CurseForge API key (Admin → Settings, encrypted).
- Filter by the server's game version and loader. Detect them from the egg (Paper/Spigot/Purpur → plugins; Fabric/Quilt/Forge/NeoForge → mods) and from server files (e.g. `version_history.json`, `.fabric`, `server.properties`); allow manual override.
- **Plugins/mods:** install = Wings "pull remote file" into `plugins/` or `mods/`. Allowed download hosts are an allowlist only: `cdn.modrinth.com`, `hangarcdn.papermc.io`, `mediafilez.forgecdn.net`, `edge.forgecdn.net`. Always pick the file matching the version/loader; check the sha1/sha512 from the API after download when available; delete it on mismatch.
- **Installed tab:** list detected jars (matched by hash against Modrinth's version-file endpoint), update available → one-click update (download the new file, remove the old one), remove.
- **Modrinth modpacks (.mrpack):**
  - show the modpack's loader and Minecraft version, and warn when they don't match the server;
  - on confirm, back up first if backups are available, then download the `.mrpack` via Wings pull, decompress it, read `modrinth.index.json`, pull each `env.server != unsupported` file to its path (allowlisted hosts and hash check), then apply `overrides/` and `server-overrides/`;
  - progress UI;
  - abort cleanly on errors.
- Permissions: a new subuser permission `plugins.manage`; reading the list needs `file.read`. Rate-limit external API calls and cache search results for 10 minutes.

## Task Z — Pay-as-you-play with auto-sleep (+ the ServerQuery helper)
- Plan options (Admin → Shop plan editor): `sleep_allowed` (bool), `sleep_discount_percent` (0–100), `sleep_idle_minutes` (default 15). Players with such a plan get an "Auto-sleep" toggle on the server's settings page and its billing summary.
- Scheduler, every minute (cheap):
  - for servers with auto-sleep on that are running, use `ServerQuery::status`; if 0 players for `idle_minutes` (consecutive checks), send a graceful stop and mark the server `sleeping` (sleep_started_at);
  - if a sleeping server is started by anyone (panel, schedule, console, wake), it is awake again;
  - sleep time is recorded per subscription period.
- Wake: a "Wake server" button for the owner and subusers with `control.start`. Public wake comes from the shareable page (Task P), using a `ServerSleep::wake(Server)` service owned here.
- Billing: at renewal, the charge = full price × (awake fraction + sleeping fraction × (1 − discount)). Round up to whole tokens. Show "You saved N tokens by sleeping" in the billing card. The renewal must stay idempotent and race-safe (existing SubscriptionService locks).
- Only Minecraft Java is supported for player detection in v1. Other games: the toggle is hidden with "not supported for this game yet".
- Implement `ServerQuery` (Minecraft Java SLP) as defined in Global constraints, with tests using a fake socket server or an injectable transport.

## Task F — Friends and permission templates
- **Friends:** users can add friends by username or email (a request; the other user accepts in an in-panel notice; no email enumeration — always respond "If that user exists, they'll get a request"). A Friends page under Account lists friends and pending requests, with remove.
- **Permission templates:** built-in templates Viewer (console read and files read), Moderator (console, power start/restart, players-related), Builder (files read/write, backups), Co-owner (everything except deleting the server, subuser management and billing). Admin-defined global templates (Admin → Settings → Templates). Owner-defined templates per account.
- In the server's Users page, "Add user" offers a friends picker plus a template picker that pre-fills the checkboxes (still editable). Changing a template later doesn't silently change existing subusers.

## Task P — Shareable server page
- Owners (and subusers with a new `share.manage` permission) can publish a public page `/s/{slug}` (slug chosen, unique, validated, reserved words blocked, 3–32 chars).
- The page shows:
  - name, description (safe markdown), a banner colour or image (upload limited to png/jpg/webp ≤ 1 MB, stored safely);
  - the game and version;
  - online status and player count via `ServerQuery`, cached;
  - the join address (the playit address only; if there's no tunnel, no address, with "ask the owner");
  - a copy button and a `minecraft://` join link where it applies;
  - a vote button (one vote per visitor per 24 h, keyed by a hashed IP plus a cookie; no raw IPs stored) with the total votes;
  - when the server is sleeping, a "Wake server" button (rate-limited 1/min per server and 5/hour per IP) that calls `ServerSleep::wake` (Task Z; create a stand-in interface if Z isn't merged: `Pterodactyl\Contracts\Servers\WakesServers::wake(Server): bool` and `isSleeping(Server): bool`).
- Open Graph tags for nice Discord embeds; noindex toggle; unpublish.

## Task K — Support tickets
- Players create tickets: subject, category (Billing, Server problem, Account, Other; admin-editable), optional server (one they can access), and a message (safe markdown). Optional image attachments: png/jpg/webp, ≤ 5 MB, up to 3 per message, stored privately and served only to people who can see the ticket.
- Threaded replies. Statuses: open → answered (staff replied) → waiting for staff (user replied) → closed (by staff or user); closed tickets reopen on a user reply within 7 days.
- Staff: a new admin tab "Support" (permission-gated; new admin permissions `support.read`/`support.reply`) with filters, assignment to a staff member, internal notes (staff-only), canned replies, and an unread count badge in the sidebar.
- Notifications: an in-panel notice and an email (Resend) on staff reply; a notice for staff on new tickets and user replies.
- Rate limit ticket creation (3/hour per user). Activity log entries.

## Task L — playit tunnels, semi-automatic
- The current state: node agent keys are read-only on playit (`/v1/tunnels/create` returns `auth: NotAllowedWithReadOnly`; the owner's keys from running agents are all read-only; only brand-new self-managed agents get write keys). So:
  - Detect it: when create returns `NotAllowedWithReadOnly`, mark the node's playit mode `read_only` (a new column or setting) and stop retrying creation for that node.
  - Show in Admin → Nodes → playit card and the Tunnels tab: "This agent key is read-only: create tunnels in the playit dashboard; the panel links them automatically."
  - Auto-link: the existing `p:tunnels:sync` (every 10 min, plus on demand from the Tunnels tab) links unlinked playit tunnels to servers by local port (and local IP when present), exactly like the import feature. Use `POST /v1/agents/rundata` (tunnels with `display_address`, `agent_config.fields` with `local_port`/`local_ip`, `disabled_reason`) as the source, falling back to the current list call. Read `display_address` as the public address.
  - The Tunnels tab lists servers on playit nodes that have no tunnel yet, with their local port, so the owner knows which tunnels to create.
  - Player-facing: a server on a playit node without a linked tunnel shows "Address pending" (not `0.0.0.0:port`) in the dashboard card, console stats and network page; the admin still sees the allocation.
  - Keep the create code path for write-capable keys, but switch it to the v1 API shape (`POST /v1/tunnels/create` with `{name, protocol:{type:"tunnel-type",details:"minecraft-java"} or raw-ports, origin:{type:"agent",data:{agent_id, config:{fields:[{name:"local_ip",value},{name:"local_port",value:"<string>"}]}}}, endpoint:{type:"region",details:{region:"global",port:null}}, enabled:true}`; delete via `POST /tunnels/delete {tunnel_id}`).
- Tests with Http::fake for: read-only detection, auto-link by port, display_address, "Address pending" output, and the v1 create body.
