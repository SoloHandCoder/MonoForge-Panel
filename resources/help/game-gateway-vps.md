---
title: Game gateway (VPS)
category: Administration
order: 2030
audience: admin
summary: Letting players join through a cheap VPS (such as Oracle Cloud's free tier) that forwards game ports over WireGuard to a node, instead of playit.gg.
---
# Game gateway (VPS)

This guide is for root administrators. A **game gateway** is a small VPS that receives the players' game traffic on a port range (TCP and UDP) and forwards it through WireGuard to a node at home. Players join `play.example.com:<the server's port>`; the node's home IP stays hidden. Only the game ports are forwarded: SSH and everything else on the VPS stay as they are, and the node keeps its normal internet connection.

While the gateway is on for a node, its servers get **no playit.gg tunnels**: none are created, synced or retried. Tunnels that already exist are left on playit.gg and shown as *replaced by gateway* under **Admin → Shop → Tunnels**. Turning the gateway off brings playit back.

## 1. Create the VPS (Oracle Cloud)

1. Create an **Always Free** Ubuntu instance (an Ampere A1 or E2 micro is plenty: it only forwards packets).
2. Upgrade the account to **Pay As You Go**. Always Free resources stay free, and Oracle no longer reclaims an "idle" instance, which a gateway that mostly forwards traffic would look like.
3. In the instance's VCN, open the subnet's **Security List → Add Ingress Rules** and allow, from `0.0.0.0/0`:
   - **UDP 51820** (WireGuard),
   - **TCP** and **UDP** for the game ports, such as `25565-25700`.

   The script opens the VPS's own firewall (iptables) itself.

## 2. DNS

Create an **A record** such as `play.example.com` pointing at the VPS's public IP. On Cloudflare it must be **DNS only** (grey cloud): Cloudflare's proxy only carries web traffic, so an orange-cloud record breaks game connections.

## 3. Run the script

Open the node in **Admin → Nodes → Settings → Game gateway**. The card shows these commands with your ports filled in, each with a copy button. Download `gateway.sh` on both machines first (the card's download command checks its SHA-256), then run with sudo, in this order:

1. **On the VPS:** `sudo bash gateway.sh vps-init --ports 25565-25700`
   It installs WireGuard and prints the VPS's public key and IP.
2. **On the node:** `sudo bash gateway.sh node-init --gateway <VPS IP> --gateway-key <VPS public key>`
   It connects to the VPS and prints the node's public key.
3. **On the VPS:** `sudo bash gateway.sh vps-add-node --node-key <node public key>`
   The ports are forwarded from now on.

Only public keys are copied between the machines; each private key stays in `/etc/wireguard` and is never printed.

Check the link on either machine with `sudo bash gateway.sh status`, and remove the gateway from a machine with `sudo bash gateway.sh remove`.

`--ports` takes 1 to 7 ports or ranges, between 1024 and 65535, such as `25565-25700,7777-7800`. SSH (22) and WireGuard (51820) are never forwarded.

### Player IPs and `--snat`

By default the game servers see each player's real IP: only replies to gateway traffic go back through the tunnel. If players cannot connect that way (some home routers or providers drop the return path), run `vps-init` again with `--snat`. The servers then see every player as the gateway (`10.66.0.1`), so IP bans and per-IP limits stop working; only use it when the default does not work.

## 4. Turn it on in the panel

On the **Game gateway** card, turn on **Use the game gateway**, enter the **public host** (`play.example.com`, or the VPS's IPv4 address; never the node's own address) and the **same ports** you passed to `--ports`, and save.

- Players now see `play.example.com:<port>` for every server whose port is forwarded. A Minecraft Java server on 25565 shows just `play.example.com`. Extra ports from **Admin → Games** (such as voice chat) get the gateway address of the matching allocation.
- A server whose port is **not** forwarded shows *Address pending*: give it an allocation inside the range.
- **Check** lists which of the node's allocations are inside the forwarded ports and which servers use a port outside them. It does not test the network. The node's **Allocations** tab marks ports outside the range as *not forwarded*.
- The shop only sells servers on this node with a free allocation inside the range. When there is none, it skips the node and root administrators get a notice saying so. Creating a server in the admin area lists the forwarded allocations first.
