---
title: Troubleshooting
category: Troubleshooting
order: 150
summary: What to do when your server will not start, players cannot connect, or you run out of tokens.
---
# Troubleshooting

## My server won't start

1. Open the server's **console** and press **Start**. Watch the output: the last lines before it stops usually name the problem.
2. If the server crashed or failed to start, the console offers to let the **AI crash doctor** check it. Choose **Diagnose**: it reads the console and the latest logs, explains what went wrong and suggests fixes you can allow. See [The AI assistant and crash doctor](/help/ai-assistant-and-crash-doctor).
3. Is the server **suspended**? A banner says so on every page; see [Renewals and unpaid servers](/help/renewals-and-unpaid-servers).
4. Is it **sleeping**? A server with auto-sleep wakes when you start it; give it a minute or two.

Common causes of a crash:

- A plugin or mod that does not match the server's version or loader. Remove the newest one from **Plugins & mods** and try again.
- A mistake in a config file you edited. Undo the change in the [file editor](/help/the-file-editor), or restore a [backup](/help/backups-and-schedules).
- The wrong game version or loader on the **Startup** page.
- Not enough memory for a large modpack. Use a bigger plan or fewer mods.

## Players can't connect

- **Is the server online?** The badge on the console must say **Online**, and the output should show it finished loading.
- **Are they using the right address?** Use the playit.gg address from the console or the dashboard, not the ports on the Network page. See [Joining your server](/help/joining-your-server).
- **Does it say "Address pending"?** The tunnel is still being set up. Wait a few minutes; if it stays, open a ticket.
- **Does the game version match?** Players need a game version the server accepts. Check the version on the **Startup** page.
- **Is a whitelist on?** Add the player to it, or turn it off, from the console or the game's config.
- **Did the address change?** If your host moved your server to a new tunnel, the console shows the new address; share it again.

## I'm out of tokens

- Your balance is on the **Wallet** page. Buy a token pack there; see [Tokens and the Wallet](/help/tokens-and-wallet).
- Got a **Balance too low for a renewal** warning? Top up before the renewal date and nothing else happens.
- Server **suspended**? Top up, then choose **Renew and unsuspend** on the server. With auto-renew on, it renews by itself once your balance covers it. Do it before the countdown ends, or the server is deleted.
- Paid but no tokens yet? Tebex can take a moment to confirm. The tokens arrive by themselves; if they have not after a while, open a ticket with the time of the payment.
- The **AI assistant** stopped? Your free AI tokens for the month are used up and your wallet is empty. Buy tokens to keep chatting.

## Still stuck?

[Open a support ticket](/help/support-tickets) with what you tried, or ask on our [Discord](https://discord.gg/B3fd45pgvC).
