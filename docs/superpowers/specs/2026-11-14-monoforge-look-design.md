# MonoForge look and branding

Date: 2026-11-14
Status: draft, waiting for approval

## Why

MonoForge Panel replaced the archived m12labs panel on 2026-11-14 and is live at
https://panel.monoforge.net. It still looks like stock Pterodactyl 2.0: the Pterodactyl logo, its grey
palette and its top navigation bar. The owner wants MonoForge to have a fresh identity of its own.

The people who open the panel are **players with game servers**, mostly Minecraft. The owner's words:
"modern and clean, solid animations just not too much, but on the main homepage you may go crazy a bit."

## Goals

- A recognisable MonoForge identity: name, mark, favicon and colours, with no Pterodactyl branding left
  in the client area or on the login pages.
- A modern, clean, dark interface with a violet accent across the client area.
- Navigation in a sidebar on the left.
- An expressive homepage (the server list players land on after logging in), with calmer motion
  everywhere else.
- MonoForge stays on Pterodactyl 2.0 and never pulls Pterodactyl's code or releases in again.

## Non-goals

- New features. Player counts, billing, store balance and Discord login are separate projects.
- A light colour scheme. MonoForge is dark only.
- Redesigning the admin area's layout. It picks up the new colours, fonts and mark, but keeps its
  current structure.
- Keeping compatibility with future upstream Pterodactyl releases.

## Anti-goals (would count as failure even if it works)

- Motion that gets in the way: anything that delays reaching a server, moves while you read, or keeps
  animating on pages other than the homepage and the login page.
- A busy or "gamer RGB" look. The homepage may be playful, but the rest stays clean.
- Losing information that the current server cards show (status, address, CPU, memory, disk).
- Server status colours changing meaning. Online stays green.

## Decisions made with the owner

These were chosen from live mockups in the browser on 2026-11-14.

### Colours: "Arcade, Violet"

Dark, neutral background with a bright violet accent. Status colours keep their meaning.

| Role | Value |
| --- | --- |
| Page background | `#0e0d15` |
| Sidebar background | `#0b0a11` |
| Card / surface | `#17151f` |
| Raised surface (tracks, secondary buttons, hover) | `#27233a` (hover row `#1a1726`) |
| Border | `#27233a` |
| Text | `#ece9f7` |
| Muted text | `#9d97b5` |
| Accent (primary buttons, active items, bars) | `#8b5cf6`, light variant `#a78bfa`, soft text `#c4b5fd` |
| Active item background | `#221c3a` |
| Online | green `#3ee07a` on `#14301f`, text `#7ef0a6` |
| Starting / stopping | amber `#f59e0b` on `#33280f`, text `#fcd34d` |
| Offline | grey `#6b6680` on `#2a2632`, text `#a8a2ba` |
| Danger | keep the existing red role |

Shapes are rounded: cards about 18px, buttons and inputs about 12px, pills fully round.

### Navigation: sidebar on the left

- Top to bottom: the MonoForge mark and name; **Servers** (with a count of the user's servers),
  **Account**, **Activity**; a **Developer** group with **API keys** and **SSH keys**; an **Admin**
  group with **Admin area**, shown only to administrators; and at the bottom the signed-in user (avatar,
  name, role) with the sign-out action.
- The active item has a `#221c3a` background, `#ddd3ff` text and a 3px glowing violet bar on its left
  edge. Other items get a soft background on hover.
- Inside a server, the sidebar shows that server's tabs instead (Console, Files, Databases, Schedules,
  Users, Backups, Network, Startup, Settings, plus any tabs added by extensions), with a
  "← All servers" link at the top. Pterodactyl 2.0 already has a side sub-navigation mode
  (`<html data-sub-navigation="side">`) to build on.
- On narrow screens the sidebar collapses behind a menu button.
- The existing global search stays reachable from the sidebar.

### Homepage (server list)

This is the one page where the motion goes up a notch.

- **Welcome area** at the top: a small uppercase "Welcome back", then a large greeting with the
  username in a slowly shimmering violet gradient, and a row of small glassy stat tiles.
- **Background behind the welcome area: floating blocks.** See-through violet squares of mixed sizes
  drift upwards from the bottom and slowly rotate, fading in and out, over a dark radial violet glow.
  Mockup reference: `hover-and-hero.html`, option 2C.
- **Server cards** rise in one after another when the page loads (short stagger, ease-out).
- **Card hover: quick actions.** The card lifts about 4px, its border turns violet with a soft violet
  glow underneath, and a row of buttons slides up: **Console**, **Restart** and **Files**. Mockup
  reference: `hover-and-hero.html`, option 1C.
- Status pills: green with a pulsing dot when online, amber with a faster pulse when starting, grey
  when offline.
- Usage bars use the violet gradient (`#7c3aed` to `#a78bfa`).

### Everywhere else

Same colours, shapes and sidebar, with only calm motion: short fades and small slides on page changes,
dialogs and menus, and hover colour transitions. Nothing loops.

### Login and other sign-in pages

The login, checkpoint (2FA), forgot-password and reset-password pages get the same floating-blocks
background behind the card, with the MonoForge mark in place of the Pterodactyl one.

### Motion rules

- Anyone whose system asks for reduced motion gets no looping animation, no stagger and no hover
  lift. Everything is shown in its final state.
- Looping animations only on the homepage welcome area and the sign-in pages.
- Animate only transform and opacity, so the panel stays smooth on weak laptops.

### Staying on Pterodactyl 2.0

- Change the stock "classic" look directly in MonoForge's code. MonoForge is now its own product.
- Turn off what could pull Pterodactyl back in:
  - `php artisan p:upgrade` downloads the official Pterodactyl release and would overwrite MonoForge.
    It should refuse to run and say MonoForge is updated from its own repository.
  - The "new version available" check calls `https://cdn.pterodactyl.io/releases/latest.json`. It
    should be off, so the panel never claims a Pterodactyl update is available.
- Leave the `upstream` git remote in place for reference, but stop merging from it.

## Approaches considered

| | Approach | Trade-off |
| --- | --- | --- |
| A | Build a separate MonoForge theme package on Pterodactyl's theme system | Survives upstream updates, but is limited to what the theme system allows |
| **B** | **Change the stock look directly in MonoForge's code (chosen)** | Can change anything; gives up easy upstream merges, which the owner no longer wants |
| C | A colours-only theme now, layout later | Quickest visible change, but most of the picked design would be missing |

The owner chose **B** and asked to stay on Pterodactyl 2.0 for good.

## Branding calls I made (owner to confirm while reading)

- **Name:** "MonoForge" everywhere the panel shows its name (sidebar, page titles, login, emails).
  m12labs called it "MonoForge Servers".
- **Mark:** there is no existing logo. Make a simple mark: a rounded violet square (`#8b5cf6` to
  `#c4b5fd` gradient) with a bold geometric "M", used in the sidebar, on the login pages and for the
  favicons and app icons. It replaces `PterodactylMark` and the files in `public/favicons`.
- **Font:** Inter, bundled with the panel (not loaded from Google).
- **Restart quick action** asks for a one-click confirmation first, because one stray click would
  otherwise kick every player off. Console and Files open straight away.
- **Stat tiles** show only what the panel already knows without new back-end work: servers online out
  of the total, and memory in use across the user's servers. A tile is left out rather than showing a
  made-up number.
- **Remove the Pterodactyl footer credit** from the client area. The MIT licence notice stays in
  `LICENSE.md`, and the README keeps its credit to Pterodactyl.

## Settled after the builder review

Facts checked on the live server on 2026-11-14, and calls made from them:

- **Production runs the stock "classic" theme with no extensions or other themes installed.** So the
  edits to classic show up directly. Keep the extension slots and view names working anyway
  (`nav.items.before/after`, `account.navigation.*`, `panel.navigation.*`, the `--brand-logo`
  setting): it costs little, keeps the SDK tests passing, and leaves room for extensions later.
- **Where the name comes from:** the live `.env` has no `APP_NAME`, so the name comes from the default
  in `config/app.php`, which already says "MonoForge" (changed in the rebrand commit). Page titles and
  emails follow it, and no server config step is needed.
- **Admin area:** one sidebar, not two. Inside the admin area, the main sidebar switches to the admin
  links (as it switches to a server's tabs inside a server), with "← Back to panel" at the top. This
  replaces the separate `AdminSidebar`. The admin pages themselves keep their structure. This is my call,
  and it overrides the earlier "admin keeps its layout" non-goal only for the navigation.
- **Account pages:** Account, Activity, API keys and SSH keys become sidebar items, and the account
  area's own sub-navigation goes away. Account pages added by extensions through
  `account.navigation.*` appear as extra sidebar items in the same group.
- **Stat tiles:** count the servers on the page being shown, from data the dashboard already loads.
  With MonoForge's 26 servers and 50 per page, that is every server. For the root admin's "other users'
  servers" view, hide the tiles. Servers you are a subuser on count towards "your servers".

## Left to the builder

- Exact animation timings and easings, within the motion rules above.
- How the colours map onto the existing CSS tokens in `packages/sdk/panel.css` and the classic skins
  (`resources/scripts/themes/classic/skins/`).
- Whether the sidebar becomes a new layout component or an adaptation of `AppLayout`.
- Mobile breakpoints and the collapsed sidebar's behaviour.
- Which upstream frontend tests to update for the new layout. All tests and lint checks in CI must pass.
- How to deploy the new build to the server (the frontend is built on the owner's PC and uploaded,
  because the server keeps Node 20 for m12labs).
