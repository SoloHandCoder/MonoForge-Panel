# Phase 5 — Editor, homepage, status page, console, token checkout

> For agentic workers: environment, commands and commit rules are in `.superpowers/sdd/night/env.md`. Owner's requests are collected in `.superpowers/sdd/phase5/requests.md`. Five independent tasks, each on its own branch from `main`.

**Global constraints**
- MonoForge look: violet accents, existing design tokens only, smooth but subtle animations (respect `prefers-reduced-motion`), mobile-friendly, accessible. Theme code lives in `resources/scripts/themes/classic/`.
- No IPs or node FQDNs anywhere public or player-facing (reuse `InfrastructureIps` / `TunnelService::displayAddress`).
- Every new endpoint has Scribe docs, the regenerated client/SDK, and a green OpenAPI contract. No `unknown` in generated types (a lint rule forbids it: give examples for empty arrays).
- New dependencies are allowed only where named here. They are lazy-loaded so the main bundle doesn't grow.
- Tests for every behaviour; PHPStan level 10; tsc/oxlint/oxfmt clean.

## Task E — VS Code-like file editor
- Dependency: `monaco-editor` plus `@monaco-editor/react` (or a thin wrapper of your own), lazy-loaded only on the editor route, with workers bundled through Vite (check that the production build works and that the extension runtime check still passes).
- The server's Files page gets an "Open in editor" action, and a full-screen editor route `/server/$id/editor?path=…` with:
  - a file-tree sidebar (lazy folders, respecting `file.read`);
  - tabs for several open files, with an unsaved dot and close/close others;
  - Ctrl/Cmd+S saves (respecting `file.update`, read-only otherwise, with a lock icon);
  - Ctrl+P quick open (fuzzy file search within loaded folders plus a server-side name search);
  - Monaco's find/replace;
  - language detection by extension (yml, json, properties, toml, js, lua, sk, cfg, ini, xml, md, sh, Dockerfile);
  - a status bar showing the language, line/column, encoding and size;
  - an unsaved-changes guard on close or navigation;
  - a large-file guard (open files over 5 MB read-only, or refuse);
  - theme colours matching MonoForge (a custom dark theme).
- Existing file endpoints are reused; add server-side support only where needed (for example a recursive name search with limits).

## Task H — Public homepage
- `/` for signed-out visitors shows a landing page (signed-in users keep the dashboard).
- Sections: a hero (MonoForge anvil logo, tagline, "Get started" → sign-up or login), "Games we host" (Minecraft Java and Terraria first, cards), live plans from the shop (a new unauthenticated `GET /api/public/store/plans` with names, resources and prices per period for public, shop-enabled nodes only; no node names or IDs; cached 60 s), features (instant setup, playit.gg addresses, an AI assistant if enabled, a web file editor, backups, Discord support), an FAQ, and a footer with the legal links and Discord.
- The animated floating-blocks style from the login page, more lively here, as the owner said "on the main homepage you may go crazy a bit". Keep it performant and honour reduced motion.
- SEO: title, meta description, Open Graph tags, and no indexing of the authenticated panel.

## Task S — Status page
- Public `/status`:
  - overall state (All systems operational / Partial outage / Major outage);
  - the panel itself;
  - each node shown by a public display name (admin-configurable, default = location short name + "Node N"), never its FQDN or IP;
  - 90-day uptime bars (daily buckets);
  - response time for the panel;
  - current announcements of level warning or danger shown as incidents.
- Backend:
  - a scheduled check every minute that records node reachability (Wings system info with a short timeout) and the panel's own health (DB + cache) into a `status_checks` table, pruned after 90 days;
  - aggregated daily rows for speed;
  - a public `GET /api/public/status`, cached 30 s.
- Admin → Settings (or a small Status tab): enable/disable the page, choose which nodes appear and set their display names.

## Task C — Server console
Redesign the server console page (keep the websocket logic):
- terminal: dark, with MonoForge colours, ANSI colour support, and highlighting of log levels (WARN amber, ERROR red, INFO muted);
- a search/filter box in the log with highlight;
- auto-scroll with a "Jump to latest" pill when scrolled up, and a pause toggle;
- copy all and download log;
- clickable links (https only, rel noopener);
- a command bar: Enter sends; ↑/↓ for history (per server, stored locally with try/catch); Tab-complete for common commands of the egg (Minecraft: say, op, deop, whitelist add/remove/list, kick, ban, pardon, gamemode, time set, weather, tp, give, stop, save-all, list, difficulty; Terraria: say, kick, ban, save, exit, time, playing, password, motd); suggestions shown above the input;
- a stats side panel: live CPU, RAM, disk and network mini-graphs (last 60 s), uptime, player count where available (Minecraft "list" parsing or query if already available, else hidden), and the server address with copy (the playit address when present);
- power buttons: start / restart / stop / kill (kill confirms), with state badges (starting, running, stopping, offline);
- a mobile layout with stats stacked under the console;
- permissions respected: no command bar without `control.console`, no power buttons without the matching permission.

## Task T — Token purchase return screen
When a player returns from Tebex checkout (`/account/wallet?tebex=…`), replace "it will appear soon" with:
1. an animated "Confirming your payment…" screen in the same style as the server-purchase loading screen. It polls a new `GET /api/client/account/wallet/purchases/{basket or transaction ref}` (owned by the user only) or the wallet balance every 2 s;
2. a success screen "+N tokens added" with the new balance and buttons "Go to store" / "Wallet", with a subtle celebration animation;
3. after 60 s, a calm state "Still confirming. Your tokens will be added automatically; you can close this page", with polling continuing in the background;
4. a clear failed or cancelled state if Tebex reports the payment was refunded or cancelled.
Tests: the polling stops on success; the states render; the endpoint is owner-only.

## Review focus
1. Public endpoints (homepage plans, status) leaking node names, IDs, IPs or anything internal.
2. The editor saving to the wrong file when tabs switch quickly, or losing unsaved changes.
3. Console XSS through ANSI or links in server output.
4. The status checks putting load on Wings or the weak CPU (they must be cheap and cached).
5. The token return page trusting query parameters (it must only display server-confirmed data).
