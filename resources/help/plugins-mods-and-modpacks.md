---
title: Plugins, mods and modpacks
category: Your server
order: 80
summary: Install and update plugins and mods from Modrinth, Hangar and CurseForge, and install modpacks with an automatic backup.
---
# Plugins, mods and modpacks

Open **Plugins & mods** on a Minecraft server to install plugins, mods and modpacks from **Modrinth**, **Hangar** and **CurseForge** without uploading files yourself. Every file is checked before it reaches your server.

## Installing

1. The page detects which loader your server runs (such as Paper, Fabric or Forge) and its Minecraft version. If it guessed wrong, choose the right one at the top.
2. On **Browse plugins** (or **Browse mods**), search a catalog and open a project.
3. Pick a version and choose **Install**. Versions that may not match your server are marked.
4. Restart the server so it loads the new plugin or mod.

## Updating and removing

The **Installed** tab lists the jars in your `plugins/` or `mods/` folder and checks them against Modrinth.

- A jar with a newer version shows **Update**.
- **Remove** deletes a jar; restart the server for it to take effect.
- Jars that are not on Modrinth are listed but cannot be updated from here.

## Modpacks

On the **Modpacks** tab, Modrinth modpacks install their mods and config files over your server's.

- **Stop the server** before installing, and start it again when the install is done.
- The panel **backs the server up first** when it has a free backup slot, so you can restore it if the modpack breaks something. Without a free slot it warns you; free a slot (or install without a backup at your own risk).
- Mods already in the `mods` folder stay; config files the modpack ships replace yours.
- A progress panel shows the install while it runs, and how it ended.

Check that the modpack's loader and Minecraft version match the server first; change them on the **Startup** page if needed.

Installing needs the plugins permission on the server. See [Backups and schedules](/help/backups-and-schedules) to restore a backup.
