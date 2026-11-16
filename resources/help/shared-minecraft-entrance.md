---
title: Shared Minecraft entrance
category: Administration
order: 2040
audience: admin
summary: One public entrance for every Minecraft Java server on a node, joined as name.play.example.com without a port, routed by host name by a Gate proxy on the node.
---
# Shared Minecraft entrance

This guide is for root administrators. With the **shared Minecraft entrance**, every Minecraft Java server on a node is joined as `<name>.play.example.com`, without a port, through **one** public entrance: a single playit.gg tunnel, or the game gateway VPS. A small proxy on the node ([Gate](https://gate.minekube.com) in Lite mode) reads the host name the player typed from the Minecraft handshake and forwards them to that server's own port.

- Only Minecraft **Java** servers are routed (Bedrock and other games keep their own addresses).
- Routed servers get **no playit.gg tunnel of their own** for the game port. Extra ports such as voice chat keep their own tunnel or gateway port.
- Each server gets a name: its public page slug when it has one, otherwise one made from its name. Owners can change it on the server's **Network** page (3 to 40 lowercase letters, digits and hyphens; names such as `www`, `play`, `admin`, `api` or `mail` are reserved).

## 1. Turn it on in the panel

Open the node in **Admin → Nodes → Settings → Shared Minecraft entrance**, then set:

- **Base domain**, such as `play.example.com`. Servers become `survival.play.example.com`.
- **Exposed through**:
  - **playit.gg**: the panel keeps one playit tunnel to the proxy on `127.0.0.1:<proxy port>` (default 25500). The proxy port must not be one of the node's allocations.
  - **Game gateway**: the gateway VPS forwards the **entrance port** (default 25565) and the proxy listens on it. The gateway's ports, and `gateway.sh --ports`, must include it, and no allocation of the node may use it. See [Game gateway (VPS)](/help/game-gateway-vps).

## 2. DNS

The card lists the records with your values. Create them at your DNS provider, **DNS only** (grey cloud on Cloudflare: its proxy only carries web traffic):

- **Wildcard**: `*.play.example.com` as a `CNAME` to the playit tunnel's host, or as an `A` record to the gateway's IP (a `CNAME` when the gateway host is a name).
- **playit only, SRV records**: a playit tunnel has its own port, so players would have to type it. One SRV record per server removes it: `_minecraft._tcp.survival.play.example.com` → `0 5 <tunnel port> survival.play.example.com`. Its target is the server's own name on purpose: through an SRV record the Minecraft client may put the SRV target in the handshake instead of the name the player typed, and with the same name either way the proxy still routes correctly. Wildcards cannot hold SRV records, so new servers need one each. Once they exist, tick **SRV records are set up** so players see addresses without the port.

Behind the gateway on port 25565 nothing else is needed: the address has no port.

## 3. Install the router on the node

Download `mc-router.sh` on the node (the card's command checks its SHA-256), then run with sudo:

- `sudo bash mc-router.sh install` installs Gate, a systemd service and a timer that fetches the routes from the panel every minute. It reads the panel URL and the node's daemon token from the Wings config (`/etc/pterodactyl/config.yml`); the token never appears on a command line or in a log.
- `sudo bash mc-router.sh status` shows whether Gate runs, the last sync and the routes.
- `sudo bash mc-router.sh update` reinstalls the script and Gate; `sudo bash mc-router.sh remove` takes it all off the node.

Only servers that are installed and not suspended are routed, always to `127.0.0.1:<their port>`. A stopped or sleeping server shows "offline or asleep, start it in the panel" in the multiplayer list; an unknown name shows "There is no server with this address". Joining either is refused with a connection error, as Gate Lite only answers the server list for them.

## 4. Player IPs (PROXY protocol)

Behind the proxy, servers see every player coming from `127.0.0.1`. Turn on **Forward player IPs** and press **Apply to servers**: the panel sets `proxies.proxy-protocol: true` in `config/paper-global.yml` of every routed **Paper, Purpur or Folia** server (the old file is kept as `paper-global.yml.monoforge-backup`), and Gate sends PROXY protocol to those servers only, once they are stopped or have restarted since the change. Press **Apply** again after restarting the ones listed as needing a restart. New Paper servers are set up automatically once installed.

Vanilla, Fabric, Forge and other servers cannot read PROXY protocol and would refuse every join with it, so they are left alone and keep seeing `127.0.0.1`.
