---
title: Setting up the shop
category: Administration
order: 2020
audience: admin
summary: Choosing the nodes the store sells on, capacity limits, Tebex token packs, and playit.gg tunnels including the automatic agent switch.
---
# Setting up the shop

This guide is for root administrators. Everything below is under **Admin → Shop** unless it says otherwise.

## Plans and prices

- **Plans** are what players buy, grouped into categories, each with its resources, the games (eggs) it runs and a price in tokens.
- **Billing periods** set how long a period lasts; **Price multipliers** make some locations cost more.
- **Add-ons** sell extra memory, disk, CPU, backups, databases or ports, per unit.
- **Coupons**, **Trials** and **Referrals** have tabs of their own.

## Nodes in the shop

The **Nodes** tab lists the nodes the store may sell new servers on.

- Only nodes that are **Available in the shop** and public get new sales.
- Taking a node out stops new sales on it; servers already bought there keep running and renewing.
- Each node can cap what the shop sells on it (memory, disk and CPU). When a purchase would pass a node's limit, the store greys that node out.

## Capacity limits

The **Limits** tab opens or closes the store and sets:

- **Days before deletion:** how long a server stays suspended when its tokens run out (0 deletes it right away).
- **Low-balance warning (days):** how many days before a renewal they cannot pay players are warned.
- **Capacity limits:** the most the shop sells in total across all shop servers, in memory, disk and CPU (0 is no limit). **Sold right now** shows what is in use. When a purchase would pass a limit, the store greys out the buy button and says it has hit its limit.

## Tebex token packs

The **Token packs & Tebex** tab connects your Tebex Headless store.

1. Enter the **public token** (Tebex creator panel → Integrations → API keys) and, optionally, the **private key**.
2. Enter the **webhook secret** (Developers → Webhooks). No tokens are credited until it is set.
3. In Tebex, point a webhook at `/api/webhooks/tebex` on this panel. Webhooks are only accepted from the allowed webhook addresses, which default to the ones Tebex documents.
4. Create a **token pack** for each fixed Tebex package: its **Tebex package ID** (the number in the package's edit URL), the tokens, optional bonus tokens, the sort order and **On sale**.

Players then see the packs on their Wallet page. Tebex has no sandbox: its Test Mode sends real webhooks and really credits tokens, so turn it off before going live. **Flagged** payments (refunded, disputed, or matching no user or pack) can be checked and credited with **Approve and credit**; refunds and lost disputes take the tokens back by themselves.

## playit.gg tunnels

Servers on a node with a playit.gg agent get their public address through a tunnel. Set a node's agent on its settings page under **Admin → Nodes**.

The **Tunnels** tab shows every tunnel and its status, with **Retry now** and **Recreate**.

- **Servers Without a Tunnel** lists servers that still show "Address pending", each with the local address to create a tunnel to in the playit.gg dashboard. The panel links a matching tunnel within ten minutes, or at once with **Import existing tunnels**.
- **Custom Subdomains** lets players pick a subdomain of your base domain at checkout, except the reserved names you list.

### The automatic switch

When playit.gg only lets the panel read a node's agent, tunnels have to be made by hand. **Set up automatic tunnels** on the node's settings page switches the node to a self-managed agent, on which the panel creates a tunnel for every server, new ones included:

1. Choose **Set up automatic tunnels**. The panel asks playit.gg for a new agent; nothing changes on the node or for players yet.
2. Open the link, sign in to playit.gg with the account the node's tunnels belong to, and accept the new agent. The page notices by itself.
3. Run the switch commands the page shows on the node. When the script asks for the switch token, paste the one the page shows: it works once and is shown only once. The script backs up the playit config, points the agent at the new key, restarts it and checks that it comes online, and puts the old config back if anything fails.
4. Servers on the node now have **new playit.gg addresses**, so tell your players. Then remove the old tunnels, so they do not count against your playit.gg tunnel limit.

To undo the switch, run `sudo bash playit-switch-agent.sh --rollback` on the node first, then choose **Undo switch** in the panel, which gives the servers their old tunnels and addresses back.
