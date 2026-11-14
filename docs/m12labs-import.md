# Moving from m12labs to MonoForge

MonoForge can import the core data of an m12labs panel: users, API keys, nests, eggs, nodes, allocations,
servers, subusers, databases and database hosts, backups, schedules and mounts, plus the activity log if you
want it. Server files on your nodes are not touched. Wings finds every server by its UUID, and the importer keeps
every UUID and ID as it was.

The importer is new. Back up both databases before you run it, and start with a dry run.

## Before you start

1. Update m12labs to its final release. The importer was built against that schema and refuses to run if
   something it needs is missing.
2. Install MonoForge on the same server or one that can reach the m12labs database, and run the migrations
   **without** seeding:

   ```bash
   php artisan migrate --force
   ```

   The importer only writes into empty tables, because it keeps the m12labs IDs.
3. Find the `APP_KEY` in the m12labs `.env` file. Node tokens, database passwords and 2FA secrets are
   encrypted with it, and the importer re-encrypts them with MonoForge's key.

## Run it

Pass the password and key as environment variables, so they don't show up in the process list:

```bash
SOURCE_DB_PASSWORD='…' SOURCE_APP_KEY='base64:…' php artisan p:migrate:m12labs \
    --host=127.0.0.1 --database=m12labs --username=m12labs --dry-run
```

The dry run does the whole import inside a transaction and then rolls it back. It shows:

* what would be copied, table by table
* m12labs tables that are not imported, and why
* columns with data that has no place in MonoForge
* warnings, such as admins who will lose admin access

When you're happy with it, run the same command without `--dry-run`. Add `--with-logs` to bring the activity
log along.

## What changes

| m12labs | MonoForge |
|---|---|
| Separate listen and public ports per node | One port per service. MonoForge uses the **public** ports, because that is where the panel and SFTP users reach the node. The importer warns about every node where the two differ. |
| No locations | All imported nodes go into a new `m12labs` location. |
| Access profiles (admin roles) | Only root administrators. Users who were admins through an access profile become regular users, and the importer lists them so you can promote them again. |
| A node points at its database host | A database host points at one node. A host shared by several nodes is linked to the first and the importer warns about it. |
| "OOM killer enabled" | "OOM killer disabled", with the value flipped. |
| Eggs grouped by nest | Nests are kept, and each egg is also tagged from its nest. |

## What is not imported

Billing (products, orders, invoices, coupons, payment data), extensions, support tickets, custom domains,
themes, the landing page, email settings, Discord and Google sign-in links, sessions and panel settings. They
stay in the m12labs database, so you can come back for them later.

## After the import

1. Sign in with an imported root administrator account.
2. On each node, point the wings-rs `config.yml` at MonoForge (`remote:`) and restart Wings. Node tokens were
   carried over, so the existing token keeps working.
3. Check a server's console and files, then retire the m12labs panel.
