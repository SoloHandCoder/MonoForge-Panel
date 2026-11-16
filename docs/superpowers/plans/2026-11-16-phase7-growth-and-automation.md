# Phase 7 — Growth and automation

> For agentic workers: environment, commands and commit rules are in `.superpowers/sdd/night/env.md`. Each task is on its own branch from `main` (after phase 6). The owner picked these on 2026-11-16 before going to sleep.

## Global constraints
- MonoForge look and the existing components; mobile-friendly (check at 375 px); accessible; reduced-motion respected.
- No IPs or FQDNs player-facing. Money paths are race-safe: row locks, idempotency keys, integer token maths, tests for concurrency and rounding.
- Every endpoint has Scribe docs, the regenerated client and SDK, and a green OpenAPI contract test, with no new `unknown`. Activity events have translations with named literal properties. New subuser or admin permissions are translated.
- The CPU is weak: scheduled work is cheap and `withoutOverlapping()->onOneServer()`; heavy work is queued.
- Tests for every behaviour; PHPStan level 10; tsc/oxlint/oxfmt clean.
- Known worktree gotchas: run phpstan with `WT_UNIT=1 … --autoload-file=<wtboot.php>`; check rector diffs by hand; Scribe needs the static-type fallback (temporarily `'type' => 'static'` in config/scribe.php, copy public/docs/openapi.yaml to storage/app/private/scribe/openapi.yaml, restore and delete public/docs); never UPDATE_FIXTURES from a worktree; scratch files must be uniquely named; never git stash.

## Task W — Scheduled restarts and backups wizard
- On the server's Schedules page, a "Quick setup" card with presets:
  - "Daily restart" (time picker, timezone shown, warning N minutes before via `say` for Minecraft or a generic console message, then restart);
  - "Backup every N hours, keep M" (uses backups; rotates the oldest unlocked backup when the limit is reached);
  - "Restart when empty for N hours" (only if ServerQuery supports the game);
  - "Weekly cleanup" (deletes logs older than N days in `logs/` via a file delete task, Minecraft only).
- Presets create normal schedules and tasks (visible and editable), tagged `preset` so the card shows which are active, with one-click disable. Respect the server's backup limit and the subuser permissions (`schedule.create`, `backup.create`).

## Task R — Revenue dashboard (admin)
- Admin → Billing → "Revenue" (a nav category of its own, or Billing):
  - KPIs for a chosen range (7/30/90 days, custom): tokens sold, money in (from tebex_payments amounts and currency, with currencies summed separately), refunds and chargebacks, net;
  - active subscriptions and MRR-equivalent in tokens per 30 days;
  - renewals due in the next 7 days (count and tokens) and how many lack the balance;
  - churn (cancelled or deleted subscriptions);
  - the top plans;
  - auto-sleep savings granted;
  - AI usage cost in tokens;
  - new users and paying users.
- Charts follow the existing chart components (accessible, light and dark).
- Data comes from the existing tables, aggregated with indexed queries and cached for 5 minutes. CSV export.
- Root admins or a new admin permission `billing.read`.

## Task C — Coupons and referrals
- **Coupons:**
  - Admin → Shop → Coupons: a code (case-insensitive, unique) of one of these types:
    - percent off the first period of a server purchase;
    - a fixed tokens-off amount;
    - free tokens on redeem (a wallet credit).
  - Limits: max redemptions total, max one per user, valid from/until, only for certain plans or categories, new customers only (no prior purchase).
  - Players enter a code at store checkout (discount shown in the quote) or on the Wallet page (free tokens).
  - Redemption is atomic: a row lock on the coupon, a unique (coupon, user) index, an idempotent purchase.
  - Audit list of redemptions.
- **Referrals:**
  - Each user has a referral link (`/r/{code}`, a cookie for 30 days, stored on sign-up).
  - When the referred user's FIRST token purchase via Tebex completes (not refunded within a hold period of 7 days), both get N tokens (admin setting, default 200, off by default).
  - Anti-abuse: no self-referral (same user, and same signup IP /24 or /48 hashed, flagged for admin review), a cap per referrer per month, and a reversal if the purchase is later refunded or charged back before payout.
  - An Account → Referrals page with the link, stats and pending/paid rewards.

## Task T — Free trial servers
- Admin → Shop → Trials: on/off, which plan(s) are trialable, duration (24/48/72 h), the max concurrent trials panel-wide, and whether a verified email is required (the account must have a confirmed email or a linked Discord/Google account).
- A player without any previous trial or purchase can start one free trial server from the store ("Try free for 48 h"). Anti-abuse: one per account; one per hashed signup/IP /24 or /48 per 30 days; one per linked Discord/Google ID; the captcha when configured; the capacity limits apply.
- The trial server is a normal server with a `trial` subscription state:
  - a banner with a countdown and "Keep this server": buy a period with tokens and the subscription converts in place, keeping the server;
  - at expiry: suspend, then delete after the shop grace period (reuse the billing engine).
- Admin list of trials, plus conversion rate (feeds the revenue dashboard if it's easy).

## Task A — Fully automatic playit (self-managed agents)
- **Background:** running agents' keys are read-only. `/claim/setup` with `agent_type:"self-managed"` and version `"playit 2.0.2"`, followed by `/claim/exchange`, returns a key that can create tunnels via `/v1/tunnels/create` (it returned `AgentVersionTooOld` only because no agent program ran with it yet).
- **Panel:** a node's playit card gets "Set up automatic tunnels":
  - the panel starts a claim with a fresh code of at most 20 hex characters, shows the claim URL for the admin to accept, polls setup and then exchange, and stores the new secret (encrypted) as the node's agent key, mode `self_managed`;
  - it shows the generated install commands for the node.
- **Node switch script** `scripts/monoforge/playit-switch-agent.sh` (run with sudo on the node; the admin pastes a one-time token the panel shows, never the secret in chat):
  - fetches the new secret from the panel through a one-time, short-lived, node-authenticated endpoint (or the admin pastes the secret into a hidden prompt);
  - backs up the current playit config;
  - points the running playit service (playitd `--secret-path` or the CLI's secret file, detected) at the new secret;
  - restarts it and verifies the agent comes online through rundata;
  - `--rollback` restores the old config.
- **After the switch:**
  - the panel's sync creates missing tunnels on the self-managed agent for every server on that node;
  - it lists the old tunnels (now on the old agent) with an admin action "Remove old tunnels" (manual in the playit dashboard if the old key is read-only);
  - it shows a clear notice that addresses changed.
- **Test thoroughly with Http::fake.** The live flow is run by the owner in the morning, so make the UI step-by-step and safe (nothing irreversible without a confirm).

## Review focus
1. Coupon and referral abuse: multiple redemptions in races, self-referral farming, rewards before refunds settle.
2. Trial farming via new accounts or alt Discord accounts; trials bypassing capacity.
3. Revenue dashboard leaking data to non-root admins; heavy queries on the weak CPU.
4. The playit switch exposing the new secret (logs, responses, shell history); a half-done switch leaving a node without tunnels (rollback must work).
5. Schedule presets exceeding backup limits or permissions.
