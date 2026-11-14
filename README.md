# MonoForge Panel

MonoForge Panel is a free, open-source game server management panel. It is a fork of
[Pterodactyl Panel](https://github.com/pterodactyl/panel) v2, built with PHP (Laravel 13) and React 19, and it runs
every game server in an isolated Docker container on your nodes.

> **Status:** early development. MonoForge is based on Pterodactyl `v2.0.0-beta.2`, which is itself a beta.
> Don't run it in production yet.

## Goals

* **A home for m12labs users.** [m12labs-panel](https://github.com/macery12/m12labs-panel) has been archived.
  MonoForge aims to be the place to move to, with an importer for existing m12labs installs
  (see [Moving from m12labs](docs/m12labs-import.md)).
* **Stay close to upstream.** New features are built as Pterodactyl v2 extensions wherever possible, so upstream
  updates stay easy to pull in.
* **Works with your existing nodes.** MonoForge talks to the standard Wings API, so it works with
  [Pterodactyl Wings](https://github.com/pterodactyl/wings) and
  [Calagopus Wings (wings-rs)](https://github.com/calagopus/wings)
  (see [wings-rs compatibility](docs/wings-rs-compatibility.md)).

## Inherited from Pterodactyl v2

* **Extension SDK:** extend the panel through a versioned API instead of editing core files.
* **Modern stack:** Vite, React 19, TanStack Router and Query, Tailwind 4 and Laravel 13.
* **One application:** the admin and client areas are a single React app.
* **One-click egg importer:** browse the egg catalog and import directly from the panel.
* **Theming with design tokens:** restyle the panel without rebuilding it.

## Keeping up with upstream

This repository tracks Pterodactyl as the `upstream` remote:

```bash
git fetch upstream
git merge upstream/2.0-develop
```

## Credits

MonoForge Panel is built on Pterodactyl® by Dane Everitt and contributors. Pterodactyl is a registered trademark of
its owners, and MonoForge is not affiliated with or endorsed by the Pterodactyl project.

## License

Code released under the [MIT License](./LICENSE.md).
