---
title: The file editor
category: Your server
order: 70
summary: Edit your server's files in the browser with tabs, quick open (Ctrl+P), save (Ctrl+S) and find and replace (Ctrl+F).
---
# The file editor

Open **Files** on a server to browse its folders. Click a text file to open it in the editor, which has syntax highlighting and keeps several files open in tabs.

## Shortcuts

On a Mac, use **⌘** instead of **Ctrl**.

- **Ctrl+P** opens a file by name. Start typing and pick it from the list; it searches the whole server, not just the folders you opened.
- **Ctrl+S** saves the file you are looking at. The save button lights up while there are unsaved changes.
- **Ctrl+F** finds text in the file, and replaces it too.

The panel warns you before you leave the editor with unsaved changes.

## What the editor opens

- Text files up to **4 MB** by default; your host can change the limit. The editor tells you when a file is bigger.
- It cannot open files that are not text, such as world files or jars.

For a file the editor will not open, download it from the file manager instead.

## Tips

- Stop the server before editing a file the game rewrites while it runs, such as world or player data, or your change may be overwritten.
- Many settings, such as `server.properties`, only apply after a restart.
- Large folders show their first 250 files in the file manager; use **Ctrl+P** in the editor to reach the rest.
