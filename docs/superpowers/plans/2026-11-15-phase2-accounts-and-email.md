# Phase 2: Sign-up, Google/Discord login and linking, Resend email, m12labs settings import

> For agentic workers: each task is built by one agent in its own git worktree, on top of phase 1. Read your task plus Global Constraints and Environment.

**Owner's words:** "sign up page not just login and that you can login with google and discord and like link it to your account"; "add support for resend for the emails i think my old api key should also be in the m12labs things"; Google/Discord app credentials were set up in m12labs and should be reused.

## Facts from the old m12labs panel (live server, database still present, read-only)
- Callback URLs registered at Discord/Google (keep them identical): `GET /auth/modules/discord/authenticate` and `GET /auth/modules/google/authenticate` on https://panel.monoforge.net. m12labs started the flow with `POST /auth/modules/{provider}` (captcha-protected) returning the provider authorize URL.
- Old settings rows (table `settings`, column `key` with prefix `settings::`): `settings::modules:auth:discord:client_id`, `...:discord:client_secret`, `...:google:client_id`, `...:google:client_secret`, `settings::modules:auth:captcha:provider|site_key|secret_key` (Cloudflare Turnstile), `settings::modules:email:resend:api_key`, `settings::modules:email:from_email`/`from_name` (names may differ slightly — read the whole `settings::modules:email:%` set), `settings::app:name`.
- Secret values were encrypted with m12labs' APP_KEY via `Crypt::encryptString` (no PHP serialization), sometimes **multiple times** — decrypt repeatedly (max 5) while the value still looks like a Laravel encrypted payload (base64 JSON with iv/value/mac).
- Old table `user_oauth_accounts`: id, user_id (same IDs as MonoForge users — the import kept IDs), provider (`discord`|`google`), provider_user_id, provider_username, provider_email, provider_avatar, timestamps; unique(provider, provider_user_id), unique(user_id, provider).

## Global Constraints
- Same as phase 1 (look, tokens, light tests, CI green, commit trailer, no new dependencies — report NEEDS_CONTEXT if you think one is required).
- MonoForge settings live in the `settings` table via `Pterodactyl\Models\Setting` and are mapped onto config by `app/Providers/SettingsServiceProvider.php` (`$keys`, `$emailKeys`, `$encrypted`). New setting keys for this phase (exact names):
  - `monoforge:auth:registration:enabled` (bool, default false)
  - `monoforge:auth:discord:enabled`, `monoforge:auth:discord:client_id`, `monoforge:auth:discord:client_secret` (encrypted)
  - `monoforge:auth:google:enabled`, `monoforge:auth:google:client_id`, `monoforge:auth:google:client_secret` (encrypted)
  - `mail:default` (`smtp|resend|log`), `services:resend:key` (encrypted)
  - `monoforge:captcha:turnstile:site_key`, `monoforge:captcha:turnstile:secret_key` (encrypted), `monoforge:captcha:provider` (`none|recaptcha|turnstile`)
  Task owners: Task F registration + captcha keys, Task G the auth provider keys, Task H mail keys. Each adds its keys to SettingsServiceProvider in its own block with a comment so merges stay simple; make sure `mail:default` is loaded before the SMTP-only email keys decision.
- Admin Settings page: each task adds its own section/tab to the existing admin settings UI (`resources/scripts/components/admin/settings/*`), named "Registration", "Login providers", "Email".
- Never log or return secrets; admin GET endpoints return `*_set: bool` instead of secret values.

## Environment
Same as phase 1 (PHP path, MariaDB with your own `testing_<task>` DB, junctions, lint commands). PHP tests in a worktree MUST use the worktree bootstrap, because the vendor junction otherwise loads the main checkout's app/: `php vendor/bin/pest --bootstrap C:/Users/lilo5/AppData/Local/Temp/claude/76b98a8c80034c99/3dffeb0f-4197-4500-9583-c7f8e873c67a/scratchpad/wtboot.php <tests>` (unit tests: prefix `WT_UNIT=1 DB_HOST=UNIT_NO_DB`), run from the worktree root.

---

### Task F: Sign-up page and captcha
- `GET /auth/register` page in the classic auth views (same `AuthCard` look as login) with username, email, password + confirm, optional captcha, terms checkbox text "I agree to the rules" (no link yet). Link "Create an account" on the login page and "Already have an account? Sign in" on register. Only shown when registration is enabled.
- `POST /auth/register` (throttled like login, captcha middleware) creating a normal user through the existing user creation action/service (validation rules reused), then signing them in.
- Captcha: support Cloudflare Turnstile in addition to the existing reCAPTCHA (provider setting), applied to login, register and the social-login start endpoints.
- Admin Settings "Registration" section: enable/disable sign-up, captcha provider + keys.
- Tests: disabled → 404/403; enabled → creates user and logs in; validation errors; captcha enforced when configured (fake verifier).

### Task G: Google and Discord login + account linking
- Implement OAuth2 authorization-code flows for Discord (scope `identify email`) and Google (scope `openid email profile`) with Laravel's Http client (no Socialite). State parameter stored in session and verified; PKCE for Google.
- Routes exactly: `POST /auth/modules/{discord|google}` → returns `{ url }` to redirect to; `GET /auth/modules/{discord|google}/authenticate` → callback.
- Migration `2026_11_15_000101_create_user_oauth_accounts_table` with the old m12labs columns/uniques above; model `UserOAuthAccount`.
- Callback behaviour: if the provider account is linked → sign that user in (2FA checkpoint still applies). If not linked and a user is signed in and came from the account page (`?link=1` flow) → link it. If not linked and nobody is signed in → if registration is enabled, create a new account (username from provider, de-duplicated; random password; email from provider when verified) and link; otherwise show "No MonoForge account is linked to this Discord/Google account." Never auto-link to an existing account by email.
- Login page: "Continue with Discord" / "Continue with Google" buttons when enabled. Account page: "Connected accounts" card listing Discord/Google with Link / Unlink (unlink blocked if it would leave the account without a password login — users created via social login must set a password first).
- Admin Settings "Login providers": enable + client id/secret per provider, shows the exact callback URL to register.
- Import command `p:migrate:m12labs-oauth-links` reading the old `user_oauth_accounts` table over a source DB connection (reuse the connection options/env vars pattern of `p:migrate:m12labs`) and inserting links for users that exist, skipping duplicates.
- Tests: state mismatch rejected; linked login; new account when registration on; refusal when off; unlink guard (Http::fake for provider endpoints).

### Task H: Resend email + m12labs settings import
- Resend mailer without new packages: a Symfony Mailer transport that posts to `https://api.resend.com/emails` with the API key (Bearer), registered as mailer `resend` (`config/mail.php` + `config/services.php` `resend.key`). Errors surface as transport exceptions.
- Admin Settings "Email": driver (SMTP / Resend / Log), Resend API key (write-only), from address/name, "Send test email" (reuse the existing test mail controller).
- Command `p:migrate:m12labs-settings` (source DB options + `SOURCE_APP_KEY` like `p:migrate:m12labs`): reads the old settings rows listed above, decrypts secrets as described (multi-layer), and writes MonoForge settings with these mappings: discord/google client id+secret → `monoforge:auth:*` (+ set `enabled` true when both present); Resend api key → `services:resend:key` and `mail:default=resend` if present; from email/name → `mail:from:address`/`mail:from:name`; Turnstile keys → `monoforge:captcha:*` with provider `turnstile` if both present. Dry-run flag printing which settings would be set (never the values). Encrypt secrets with MonoForge's key the same way SettingsServiceProvider expects.
- Tests: transport sends the right payload (Http::fake); decrypt handles single and double encryption; dry run writes nothing; mapping.
