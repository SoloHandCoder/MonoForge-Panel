---
title: Operating MonoForge
category: Administration
order: 2010
audience: admin
summary: Keeping the panel up to date with Check now and Update now, the queue worker install script, the tune script, and backups.
---
# Operating MonoForge

This guide is for root administrators. Commands run on the panel host, from the panel folder (usually `/var/www/monoforge`), as a user who can use sudo.

## Updates

New MonoForge builds come from the GitHub releases of the MonoForge Panel repository.

- **Admin → Settings → General → MonoForge Updates** sets the mode: **Notify me** (checks daily), **Install automatically** (checks hourly) or **Off**. When `MONOFORGE_UPDATE_MODE` is set in `.env`, the panel shows the mode read-only; change it in `.env`.
- The **Overview** page shows the build you run and whether a newer one is out.
- **Check now** asks GitHub straight away (a few times a minute at most).
- **Update now** installs the newer build. The panel scheduler starts it within a minute.

An update puts the panel into maintenance mode for a short while (players see a maintenance page), backs up the code and the database, copies the new build into place while keeping `.env` and `storage/`, runs the migrations and rebuilds the caches. If a step fails, the previous code is put back.

- The update backups live in `storage/app/updates`; the newest 3 are kept by default.
- If an update was cut off half way, `php artisan p:monoforge:recover` puts back the code from before it and leaves maintenance mode.
- `php artisan p:monoforge:update --check` reports from the command line whether a newer build is out.

## The worker install script

The AI assistant and the plugin, mod and modpack installs run on queue workers of their own, outside the normal `monoforge-queue` worker:

- `monoforge-assistant-queue` runs AI assistant turns;
- `monoforge-installs-queue` runs plugin, mod and modpack installs and the installed-jar scans.

Install or update both with `sudo bash scripts/monoforge/install-assistant-worker.sh`. It is safe to run again: it rewrites the systemd units and restarts the workers. `--remove` stops and removes both. Without them, assistant chats and installs wait in the queue.

## The tune script

`sudo bash scripts/monoforge/tune-server.sh` tunes a small multi-core host with spare memory: more PHP-FPM workers, a bigger OPcache, the phpredis extension, Redis for the cache and sessions, long-lived caching and gzip for the build assets, and Laravel's config, route and view caches.

- It backs everything up first, to `/var/backups/monoforge-tune-<time>`, and rolls back by itself if the site stops answering.
- `sudo bash scripts/monoforge/tune-server.sh --rollback <backup folder>` restores a backup it made.
- Moving sessions to Redis signs everyone out once.

## Backups

- **Panel code and database:** every update backs them up first (see above). Take database dumps of your own as well, for example nightly with `mysqldump`, and keep a copy of `.env`: its `APP_KEY` is needed to read the encrypted values in the database.
- **Player servers:** players make their own backups within their plan's limit, and schedules can rotate them. They are stored on the nodes or in S3; **Admin → Storage** shows how much space they use.
