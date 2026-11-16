---
title: The AI assistant and crash doctor
category: AI assistant
order: 100
summary: Ask the AI assistant about your server, approve its actions with Allow or Deny, understand its free allowance and token costs, and what it cannot see.
---
# The AI assistant and crash doctor

Every server has an **Assistant** page with an AI that knows your server. Ask it things like "Why did my server crash?", "What uses the most disk space?" or "Explain my startup settings". It can read the console and your files, and it asks before it changes anything.

## Allow and Deny

The assistant shows every action it takes as a card in the chat, with every detail of what it will do.

- Most reading, such as the console or an ordinary file, happens straight away. Reading a file that may hold secrets, such as a `.env` file, asks first.
- Anything that changes your server, such as editing or deleting files, sending a console command, power actions, making a backup or changing a startup setting, waits for you. The card shows **Allow** and **Deny**; the assistant only goes on once you choose.
- Cards that would delete or overwrite something are shown in red. Read them carefully.
- If a request contains hidden or direction-changing characters, the card points them out. Only allow it if you expected them.

You can only allow what your own permissions on the server allow.

## Free allowance and token costs

The assistant runs on AI tokens, which are not the same as your panel tokens.

- Every month you get a **free allowance** of AI tokens. The usage meter under your conversations shows how much of it you have used this month.
- Past the free allowance, the assistant is paid from your **panel token balance**, at the rate the usage meter shows (a number of AI tokens per panel token).
- You are warned when your free tokens run low and when they are used up. With no free tokens and an empty wallet, the assistant stops until you [buy tokens](/help/tokens-and-wallet).

## What it can't see

- **Secrets are masked.** Passwords, API keys, tokens, webhook URLs, private keys and similar values in your files and startup settings are replaced with `[hidden-secret]` before the AI reads them. It never writes a masked value back into a file.
- When the assistant asks to show you a secret startup value, only you see it; the AI is told it was shown, never the value.
- IP addresses and node host names are hidden from it too. It still knows your server's public playit.gg address.

## The crash doctor

When your server crashes or fails to start, its pages show **Your server crashed. Let the AI doctor check it?** Choose **Diagnose** and the doctor reads the console and the latest logs, explains what went wrong and suggests fixes, which only run when you allow them. Its diagnosis opens in the assistant.

The console also has **Diagnose with the AI doctor** for a server that misbehaves without crashing. The crash doctor uses your AI tokens, like any chat.

Your host decides whether the assistant and the crash doctor are turned on, and the server owner decides who may use them.
