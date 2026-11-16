---
title: Backups and schedules
category: Your server
order: 90
summary: Make, download, restore and lock backups, and set up common schedules in a click with the quick setup presets.
---
# Backups and schedules

## Backups

Open **Backups** on a server. The page shows how many backups you have made out of the number your plan allows.

- **New backup** makes one now. Give it a name, list files and folders to leave out if you like, and tick **Locked** to protect it.
- Each backup's menu has **Download**, **Restore** and **Lock** or **Unlock**.
- **Restore** stops the server and puts the backup back. You can choose to delete all files first, so nothing from after the backup is left. Until it finishes you cannot use the power buttons, the files or make another backup.
- A **locked** backup cannot be deleted, by you or by a schedule, until you unlock it.
- Deleting a backup is permanent.

Need more backup slots? Some plans offer extra backups as an add-on when you buy. See [Buying a server](/help/buying-a-server).

## Schedules

Open **Schedules** to run tasks on a timer, such as commands, power actions or backups. Times are in the panel's timezone, which the page shows.

### Quick setup

The top of the page sets up common schedules in a click. Each one then appears in the list below, where you can edit, run or delete it. Each preset can be set up once per server; disable it to set it up again.

- **Daily restart** restarts the server at the same time every day. It can warn players a few minutes ahead with a chat message (such as `say`), and again one minute before. Warning players needs console access; set the warning to 0 to restart without one.
- **Backup rotation** makes a backup every few hours and keeps a set number. Once the schedule has made that many, it deletes its oldest unlocked backup to make room. Lock a backup to keep it for good.
- **Restart when empty** restarts the server once nobody has been online for the hours you choose. It checks every 15 minutes while the server runs, and never restarts on a guess when the game does not answer.
- **Weekly cleanup** deletes `.log` and `.log.gz` files in `logs/` older than the days you choose, every Monday morning. `latest.log` is kept.
