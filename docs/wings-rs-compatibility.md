# wings-rs compatibility

MonoForge Panel is based on Pterodactyl Panel `v2.0.0-beta.2`. This page records how it lines up with
[Calagopus Wings (wings-rs)](https://github.com/calagopus/wings), the Rust rewrite of Wings, as of wings-rs commit
from 2026-11-13. The check was done by reading both codebases. It has not been run against a live node yet.

## Result

wings-rs works with MonoForge. Two problems were found and fixed in the panel, and two smaller gaps remain.

### Fixed

| Problem | What users saw | Fix |
|---|---|---|
| **Console log endpoint.** The panel sent `?size=N` and expected JSON (`{"data": [...]}`). wings-rs reads `?lines=N` and returns plain text. | Every call to read a server's recent console output failed with a daemon connection error. | `ServerClient::logs()` sends both `size` and `lines` and accepts either a JSON or a plain-text response, so both Wings versions work. |
| **S3 streaming backups.** wings-rs streams S3 backups by default through `/api/remote/backups/{uuid}/s3/parts`, which the panel does not have. | Each S3 backup waited through about 15 seconds of 404 retries before falling back to a normal upload. | The generated node config sets `system.backups.s3.streaming: false`. Pterodactyl Wings ignores the key. |

### Remaining gaps (not breaking)

* **Node telemetry.** The panel asks `/api/system?v=2` for Docker and system details. wings-rs ignores `v` and
  returns only architecture, CPU count, kernel and OS, so the telemetry fields come back empty. Nothing errors.
* **Error messages.** Panel errors are `{"errors": [{"code", "detail"}]}`, while wings-rs expects a list of strings,
  so wings-rs logs show the raw response body instead of a clean message. Cosmetic only.

### Verified as compatible

**Panel → Wings:** bearer auth, `/api/system`, `/api/update`, `/api/deauthorize-user`, server create, details,
delete, sync, reinstall, power, commands and transfer, backup create, restore and delete, every file endpoint
(contents, write, list-directory, create-directory, rename, copy, delete, compress, decompress, chmod, pull), and
the websocket, file download, backup download, file upload and transfer JWTs (HS256 signed with the node token).
wings-rs never uses `deny_unknown_fields`, so extra fields the panel sends are ignored safely.

**Wings → Panel (`/api/remote`):** server reset, the paginated server list, server configuration, install script
and install result, transfer success and failure, SFTP auth (password and public key), activity, backup part URLs,
backup completion and restore status. The `config.yml` the panel generates parses in wings-rs.

### wings-rs features the panel does not use

wings-rs has extra endpoints that only the Calagopus panel calls: restic, PBS and Kopia backups, database backups,
file search, revisions and trash, utilization history, crash logs, schedules run by the daemon, and tunnels. These
are candidates for future MonoForge features.

### Not verified

* A single server whose egg config wings-rs cannot parse fails the whole server list, and wings-rs exits at boot.
  The panel already validates egg JSON, but unusual egg configs were not checked.
* wings-rs rejects JWTs issued before it last started. A token issued just before a wings restart fails. This is expected.
