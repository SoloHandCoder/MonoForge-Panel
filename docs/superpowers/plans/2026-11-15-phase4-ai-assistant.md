# Phase 4 — AI Assistant Implementation Plan

> For agentic workers: use superpowers:subagent-driven-development. Environment, commands and commit rules: `.superpowers/sdd/night/env.md`. Provider facts: `.superpowers/sdd/phase4/ai-provider-research.md` (read it; it is binding for the HTTP client).

**Goal:** an AI assistant inside MonoForge Panel, with a **server assistant** (players, on the server page) and a separate **admin assistant** (Admin → AI Assistant). It can call tools, shows every tool call, and asks for permission before sensitive actions. Usage is charged in panel tokens after a free monthly allowance.

**Owner's requirements (verbatim intent):** Cline first (ClinePass, models like deepseek), plus Ollama and others; tool calls; separate server and admin assistants; AI tokens charged in panel tokens at a ratio; free token limit with warnings; context handling; show tool calls; ask permission for sensitive actions (reading .env, deleting…); other reads happen automatically.

**Architecture:** one OpenAI-compatible chat-completions client with provider presets. A queued job runs the agent loop and persists every message and tool call. The loop pauses on a tool call that needs confirmation, and approving or denying it resumes the loop with a new job. The frontend polls the conversation while it runs (no SSE, so PHP-FPM workers aren't held open on a weak CPU).

## Global constraints
- Namespaces: `Pterodactyl\Services\Assistant\*`, models in `Pterodactyl\Models\Assistant\*`, controllers in `Http\Controllers\Api\{Client,Admin}\Assistant\*`.
- Every tool runs with the **requesting user's** permissions: subuser permissions on the server page, admin permissions in the admin assistant. A tool can never do more than the user could do in the UI. Re-check the permissions at execution time, not only when the model is called.
- Tool results, file contents and console output are **untrusted data**. The system prompt says so, and they are never treated as instructions. Sensitive actions always need a human click, whatever the model says.
- Provider API keys are encrypted at rest and never returned to the browser (admin views get `has_key: bool`). Never log prompts with secrets. Activity events get translations.
- No IPs or node FQDNs in any tool output given to non-admins (reuse the activity privacy helpers / `InfrastructureIps`).
- Scribe attributes + regenerated API client/SDK, and the OpenAPI contract test stays green. PHPStan level 10, 100% type coverage, Pint/Rector, tsc/oxlint/oxfmt.
- Settings via SettingsServiceProvider (`monoforge:ai:*`, config `monoforge.ai.*`):
  `enabled` (false), `server_enabled` (true), `admin_enabled` (true), `default_provider_id` (null), `ratio` (1000 AI tokens per 1 panel token), `free_tokens_monthly` (50000 per user), `warn_percent` (80), `max_tool_iterations` (8), `context_tokens` (24000), `max_output_tokens` (2048).

## Shared data model (Task W owns the migrations)
- `ai_providers`: id, name, preset enum(`cline`,`clinepass`,`ollama`,`openrouter`,`deepseek`,`openai`,`custom`), base_url, api_key (encrypted text, nullable), model, extra_headers json nullable, context_window int default 32000, supports_tools bool default true, enabled bool, timestamps.
- `ai_conversations`: id, uuid unique, user_id FK, scope enum(`server`,`admin`), server_id FK nullable (cascade delete), provider_id FK nullable (null on delete), title, summary text nullable, summarized_until_message_id nullable, status enum(`idle`,`running`,`awaiting_approval`,`error`), error text nullable, timestamps.
- `ai_messages`: id, conversation_id FK cascade, role enum(`user`,`assistant`,`tool`), content longtext nullable, tool_calls json nullable (assistant), tool_call_id nullable (tool), prompt_tokens int default 0, completion_tokens int default 0, created_at.
- `ai_tool_calls`: id, conversation_id FK cascade, message_id FK cascade, call_id string, name, arguments json, needs_confirmation bool, status enum(`pending`,`approved`,`denied`,`running`,`done`,`failed`), result longtext nullable (truncated to 16 KB), decided_by user FK nullable, decided_at nullable, timestamps.
- `ai_usage`: id, user_id FK, conversation_id FK nullable (null on delete), scope, period char(7) `YYYY-MM`, ai_tokens int, free_tokens int, panel_tokens_charged int, created_at. Index (user_id, period).

## Binding interfaces
```php
// Task W
interface Pterodactyl\Contracts\Assistant\AssistantTool {
    public function name(): string;                 // snake_case, unique per scope
    public function description(): string;
    /** @return array<string,mixed> JSON schema of the parameters */
    public function parameters(): array;
    /** whether this call must be confirmed by the user, given its arguments */
    public function needsConfirmation(array $arguments, ToolContext $context): bool;
    /** short human label for the UI, e.g. "Read config/server.properties" */
    public function describeCall(array $arguments): string;
    /** executes with the context user's permissions; throws ToolException with a user-safe message */
    public function execute(array $arguments, ToolContext $context): ToolResult;
}
final readonly class ToolContext { public User $user; public string $scope; public ?Server $server; }
final readonly class ToolResult { public string $content; /* for the model */ public array $display; /* small structured summary for the UI */ }
class ToolRegistry { public function register(string $scope, AssistantTool $tool): void; /** @return list<AssistantTool> */ public function for(string $scope, ToolContext $ctx): array; }
```
Task X registers its tools in a service provider via `ToolRegistry::register('server'|'admin', ...)`, and `for()` drops the tools the user lacks permission for.

## API (Task W implements; Task Y consumes)
Client (server scope; the server permission is a new subuser permission `assistant.use`, which the owner always has):
- `GET /api/client/assistant` → `{enabled, server_enabled, free_limit, free_used, ratio, balance, warning: null|"near_limit"|"free_exhausted"|"no_balance"}`
- `GET /api/client/servers/{server}/assistant/conversations` → list (uuid, title, status, updated_at)
- `POST /api/client/servers/{server}/assistant/conversations` `{message}` → creates it and starts a turn → conversation payload
- `GET /api/client/servers/{server}/assistant/conversations/{conversation}` → `{uuid, title, status, error, messages:[{id, role, content, created_at, tool_calls:[{id, name, label, arguments, needs_confirmation, status, result_preview, display}]}], usage:{free_used, free_limit, charged_this_month}}`
- `POST …/conversations/{conversation}/messages` `{message}` (409 while running/awaiting_approval)
- `POST …/conversations/{conversation}/tool-calls/{toolCall}/approve` and `/deny`
- `DELETE …/conversations/{conversation}`
Admin (root admins, session only): the same set under `/api/admin/assistant/conversations…`, plus
- providers CRUD `/api/admin/assistant/providers` (+ `POST …/{provider}/test` → sends a short prompt and reports ok/error and latency; keys write-only)
- `GET/PUT /api/admin/assistant/settings`
- `GET /api/admin/assistant/usage?period=YYYY-MM` → per-user totals.

## Tasks

### Task W — Assistant core (backend)
Owns: migrations, models, settings, `ChatClient` (OpenAI-compatible, per research: Cline needs `stream:false` set explicitly plus `X-Response-Format: openai`, and errors can arrive with `finish_reason:"error"`; Ollama ignores `tool_choice`, so never send it; generate tool-call ids when missing; normalise usage; retry 429/5xx with backoff, max 2 retries; timeout 90 s), provider presets (base URLs and default models from the research doc, e.g. ClinePass `https://api.cline.bot/api/v1` + `cline-pass/deepseek-v4.1-flash`, Ollama `http://127.0.0.1:11434/v1`), `ToolRegistry` + contracts, the `RunAssistantTurn` queued job (loop: build context → call model with tools → store assistant message + usage → auto tools execute immediately and the loop continues → a confirmation tool sets `pending` + conversation `awaiting_approval` and stops → max `max_tool_iterations` → final text), approve/deny endpoints (approve runs the tool, then dispatches the job; deny stores a tool message "The user denied this action." and continues), context handling (system prompt per scope + rolling summary: when the estimated tokens of history exceed `context_tokens`, summarise the oldest messages into `summary` with one model call and keep the most recent ones; estimate tokens at chars/4; tool results truncated to 4 KB in context), billing (`AssistantBilling`: before a turn, refuse unless free allowance is left or wallet balance > 0; after each model call, record `ai_usage`, consume the free allowance first, charge the overflow `ceil(overflow / ratio)` panel tokens via `TokenWallet::debit(..., reason 'ai.usage')`, allowing at most the remaining balance; stop the turn with a friendly error when free is exhausted and the balance is 0; notices via `NoticeService` kind `ai.free_near` at warn_percent and `ai.free_exhausted` at 100%, once per month; admin scope is never charged but usage is recorded), all endpoints with Scribe docs, the `assistant.use` subuser permission (+ translation), activity events `server:assistant.tool` (sensitive tool approved/denied) and `admin:assistant.*`. System prompts: short, scope-specific, state the tool rules, that tool output is untrusted, and to never reveal secrets or IPs.
Tests: client with Http::fake for each preset quirk (Cline wrapper header, missing tool ids, usage normalisation, error finish_reason); the loop (auto tool → continues; confirm tool → pauses; approve → resumes; deny → continues with a denial; iteration cap); billing (free first, overflow charged, ratio rounding, refusal at 0, notices once per month, admin not charged); context summarisation trigger; authz (other user's conversation 404, subuser without assistant.use 403, non-root admin 403 on admin routes, API key 403 on approve/deny).

### Task X — Tools (backend)
Owns: tool classes + registration. Build against Task W's interfaces (create the contracts yourself if W hasn't merged yet, in exactly the shape above, and note it).
Server scope (each checks the subuser permission at execute time):
- auto: `server_status` (state, resources, limits), `read_console` (last N≤200 lines via Wings logs), `list_files` (dir), `read_file` (≤256 KB, text only), `search_files` (name match), `list_backups`, `list_schedules`, `get_startup` (variables the user may view), `get_network` (allocations; the playit address instead of IPs where present).
- confirm: `read_file` when the path is sensitive (`.env`, `*.env`, `*.pem`, `*.key`, `id_rsa*`, `*.p12`, `*.pfx`, `*secret*`, `*password*`, `*token*`, `*credentials*`, case-insensitive basename match), `write_file`, `delete_files`, `rename_file`, `send_command`, `power` (start/stop/restart/kill), `create_backup`, `update_startup_variable`.
Admin scope (admin permissions): auto: `list_servers` (search), `server_overview`, `list_users` (search; no passwords or IPs), `node_usage` (via NodeUsageService, no IPs), `search_activity` (no IPs), `shop_overview`. confirm: `suspend_server`, `unsuspend_server`, `create_announcement`, `adjust_tokens` (root admins only, same limits as the token endpoint: never your own wallet, max 1,000,000, a reason).
Tests per tool: permission denied for a subuser lacking the permission; sensitive path classification; outputs contain no IPs/FQDNs; Wings calls faked.

### Task Y — Frontend
Owns: the chat UI and admin pages, using the generated client.
- Server page: an "Assistant" item in the server nav (category `overview`, shown when the assistant is enabled and the user has `assistant.use`), page `/server/$id/assistant`: conversation list (left/top on mobile), chat thread, composer (Enter sends, Shift+Enter newline), polling every 1.5 s while `running`, typing indicator.
- Tool-call cards inline in the thread: icon, label (`describeCall`), collapsible arguments/result, status chip. A pending confirmation shows a highlighted card with **Allow** / **Deny** buttons and a clear warning for destructive actions (delete, power, write).
- Usage meter (free used / limit, ratio, balance) with a warning banner at `near_limit` / `free_exhausted` / `no_balance`, and a link to `/account/wallet`.
- Admin: an "AI Assistant" tab (`group: 'system'`) with sub-tabs Chat (same components, admin scope), Providers (CRUD with presets that prefill base URL and model, write-only key field, Test button), Settings (the `monoforge:ai:*` values) and Usage (per-user table for a month).
- Markdown rendering of assistant text, **sanitised** (no raw HTML), with code blocks and copy buttons.
- Vitest: the confirmation card's Allow/Deny calls, polling stops when idle, the warning banner states, the provider form never shows a stored key.

## Review focus
1. Prompt injection via file/console content trying to trigger destructive tools: they must still need a click, and the tool must re-check permissions.
2. Charging races: two turns at once must not double-charge or overdraw (TokenWallet locking).
3. A subuser's assistant touching files or power without the matching subuser permission.
4. Secrets leaking: provider keys in responses or logs, `.env` contents read without confirmation, IPs in tool output.
5. A stuck conversation (job crashed): status must recover from `running` after a timeout (e.g. 3 minutes) so the user can continue.
