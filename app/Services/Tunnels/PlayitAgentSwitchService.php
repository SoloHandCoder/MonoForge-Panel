<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Enum\PlayitClaimStatus;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Jobs\Tunnels\ProvisionTunnelJob;
use Pterodactyl\Models\Billing\PlayitAgentClaim;
use Pterodactyl\Models\Billing\PlayitOldTunnel;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * "Set up automatic tunnels": moves a node from the playit.gg agent the owner installed (whose
 * key playit only lets read) to a self-managed agent the panel claimed, whose key may create
 * tunnels.
 *
 * 1. `start()` registers a fresh claim code with playit; the administrator opens the claim URL
 *    and accepts it. `check()` polls the claim and exchanges it for the new agent's secret.
 * 2. `issueToken()` makes a one-time token the administrator pastes into the node's switch
 *    script, which fetches the secret with it once (`fetch()`, authenticated as the node too).
 * 3. The script points the node's playit service at the new secret, restarts it and reports
 *    back (`report()`), which finishes the switch (`finish()`): the old tunnels are recorded,
 *    the node's agent becomes the new one and every server gets a new tunnel. An administrator
 *    can also finish by hand.
 * 4. `undo()` puts the old agent back (after `--rollback` on the node); `removeOldTunnels()`
 *    deletes the old agent's tunnels on playit when its key allows it.
 *
 * The secret is only ever stored encrypted, sent to playit in the agent key header and handed
 * to the node once; it is never logged, put in a message or in an activity entry.
 *
 * @phpstan-type OldTunnelRow array{
 *     id: int,
 *     playit_tunnel_id: string,
 *     name: string|null,
 *     address: string|null,
 *     local_port: int|null,
 *     server_id: int|null,
 *     server_name: string|null,
 * }
 * @phpstan-type SwitchState array{
 *     mode: string|null,
 *     self_managed: bool,
 *     switched_at: string|null,
 *     can_undo: bool,
 *     claim: array{
 *         status: string,
 *         claim_url: string|null,
 *         expires_at: string|null,
 *         agent_id: string|null,
 *         token_expires_at: string|null,
 *         fetched_at: string|null,
 *         report: string|null,
 *         reported_at: string|null,
 *         last_error: string|null,
 *     }|null,
 *     old_tunnels: list<OldTunnelRow>,
 *     old_tunnels_removed: int,
 *     script: array{url: string, sha256: string|null},
 * }
 */
class PlayitAgentSwitchService
{
    /** How long the administrator has to accept the claim on playit.gg. */
    public const int CLAIM_MINUTES = 30;

    /** How long the one-time token may be used to fetch the secret. */
    public const int TOKEN_MINUTES = 15;

    /** How long after fetching the secret the script may report how the switch went. */
    public const int REPORT_HOURS = 2;

    /** How often the switch script's status checks may try creating a tunnel. */
    public const int PROBE_SECONDS = 20;

    public const string NOT_SELF_MANAGED = 'playit.gg says the claimed agent is not a self-managed agent, so the panel will not use it. Start again.';

    /** Where the panel serves the switch script (see routes/base.php). */
    public const string SCRIPT_PATH = '/scripts/playit-switch-agent.sh';

    public function __construct(private readonly PlayitClient $client, private readonly TunnelService $tunnels) {}

    /** The script the node runs, as it is shipped with the panel. */
    public static function scriptFile(): string
    {
        return base_path('scripts/monoforge/playit-switch-agent.sh');
    }

    /**
     * Register a fresh claim code with playit.gg, replacing any unfinished claim of the node.
     *
     * @throws PlayitException
     */
    public function start(Node $node): PlayitAgentClaim
    {
        // At most 20 hex characters, as /claim/setup accepts; the official agent uses 10.
        $code = bin2hex(random_bytes(10));
        $status = $this->claimStatus($this->client->claimSetup($code));

        return PlayitAgentClaim::query()->updateOrCreate(['node_id' => $node->id], [
            'code' => $code,
            'status' => $status,
            'agent_id' => null,
            'secret' => null,
            'token_hash' => null,
            'token_expires_at' => null,
            'fetched_at' => null,
            'report' => null,
            'reported_at' => null,
            'last_error' => null,
            'expires_at' => CarbonImmutable::now()->addMinutes(self::CLAIM_MINUTES),
        ]);
    }

    /**
     * Move a waiting claim on: ask playit whether the administrator accepted it and, once they
     * did, exchange it for the new agent's secret and read the agent's id with it. Never throws
     * for playit errors; they are kept on the claim.
     */
    public function check(PlayitAgentClaim $claim): PlayitAgentClaim
    {
        Cache::lock('playit-claim:'.$claim->node_id, 30)->get(function () use ($claim): void {
            $claim->refresh();
            if (! $claim->status->isWaiting()) {
                return;
            }

            if ($claim->secret === null && $claim->expires_at instanceof CarbonImmutable && $claim->expires_at->isPast()) {
                $claim->fill(['status' => PlayitClaimStatus::Expired, 'last_error' => 'The claim was not accepted in time. Start again.'])->save();

                return;
            }

            try {
                $this->advance($claim);
            } catch (PlayitException $playitException) {
                $claim->fill(['last_error' => mb_substr($playitException->getMessage(), 0, 500)])->save();
            }
        });

        return $claim;
    }

    /**
     * A one-time token for the node's switch script. Returned once; only its hash is kept.
     */
    public function issueToken(PlayitAgentClaim $claim): string
    {
        throw_if($claim->status !== PlayitClaimStatus::Ready || $claim->secret === null || $claim->agent_id === null, DisplayException::class, 'Accept the claim on playit.gg first.');

        $token = bin2hex(random_bytes(24));
        $claim->fill([
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => CarbonImmutable::now()->addMinutes(self::TOKEN_MINUTES),
            'fetched_at' => null,
            'report' => null,
            'reported_at' => null,
        ])->save();

        return $token;
    }

    /**
     * The new agent's secret for the node's switch script, once per token. Null when the token
     * is unknown, expired or already used.
     *
     * @return array{secret_key: string, agent_id: string}|null
     */
    public function fetch(Node $node, string $token): ?array
    {
        $claim = $this->claimForToken($node, $token);
        if (! $claim instanceof PlayitAgentClaim
            || $claim->status !== PlayitClaimStatus::Ready
            || $claim->fetched_at !== null
            || ! $claim->token_expires_at instanceof CarbonImmutable
            || $claim->token_expires_at->isPast()
            || $claim->secret === null
            || $claim->agent_id === null) {
            return null;
        }

        // Only one request can use the token, however many arrive at once.
        $used = PlayitAgentClaim::query()
            ->whereKey($claim->id)
            ->whereNull('fetched_at')
            ->update(['fetched_at' => CarbonImmutable::now(), 'token_expires_at' => CarbonImmutable::now()]);

        return $used === 1 ? ['secret_key' => $claim->secret, 'agent_id' => $claim->agent_id] : null;
    }

    /**
     * What the switch script reports after it fetched the secret: `switched` finishes the switch,
     * `rolled_back` undoes it when the panel had already finished it. Returns false when the
     * token is not one whose secret was fetched in the last REPORT_HOURS.
     */
    public function report(Node $node, string $token, string $outcome): bool
    {
        $claim = $this->claimForToken($node, $token);
        if (! $claim instanceof PlayitAgentClaim
            || ! $claim->fetched_at instanceof CarbonImmutable
            || $claim->fetched_at->lt(CarbonImmutable::now()->subHours(self::REPORT_HOURS))) {
            return false;
        }

        $claim->fill(['report' => $outcome, 'reported_at' => CarbonImmutable::now()])->save();

        if ($outcome === PlayitAgentClaim::REPORT_SWITCHED && $claim->status === PlayitClaimStatus::Ready) {
            $this->finish($claim);
        }

        if ($outcome === PlayitAgentClaim::REPORT_ROLLED_BACK) {
            $node->refresh();
            if ($node->isPlayitSelfManaged() && $node->playit_agent_id === $claim->agent_id && $node->playit_previous_agent_id !== null) {
                $this->undo($node);
            }
        }

        return true;
    }

    /**
     * How the node's tunnels are doing after the switch, for the switch script to tell whether
     * playit accepts tunnels for the new agent (it refuses with AgentVersionTooOld until the agent
     * program runs with the key). While none was created yet, one is tried right away, at most
     * every PROBE_SECONDS. Null for a token that was not used to fetch the secret recently.
     *
     * @return array{tunnels: int, created: int, active: int, waiting_for_agent: int, last_error: string|null}|null
     */
    public function status(Node $node, string $token): ?array
    {
        $claim = $this->claimForToken($node, $token);
        if (! $claim instanceof PlayitAgentClaim
            || ! $claim->fetched_at instanceof CarbonImmutable
            || $claim->fetched_at->lt(CarbonImmutable::now()->subHours(self::REPORT_HOURS))) {
            return null;
        }

        return $this->tunnelCounts($node->refresh());
    }

    /**
     * "Check status" on the node's playit card: brings the panel in line with the agent the node
     * really runs. A script report the panel never acted on (a lost answer) is applied: a
     * `switched` report finishes the switch and a `rolled_back` one undoes it. Then the node's
     * key is checked against playit's run data, missing tunnels are queued and, on a
     * self-managed agent, one tunnel is tried so the counts show whether the agent is online.
     *
     * @return array{action: string, message: string, agent_online: bool|null, tunnels: int, created: int, active: int, waiting_for_agent: int, last_error: string|null}
     */
    public function reconcile(Node $node): array
    {
        $node->refresh();
        $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->first();
        $action = 'none';

        if ($claim instanceof PlayitAgentClaim && $claim->report === PlayitAgentClaim::REPORT_SWITCHED && $claim->status === PlayitClaimStatus::Ready) {
            $this->finish($claim);
            $action = 'finished';
        } elseif ($claim instanceof PlayitAgentClaim
            && $claim->report === PlayitAgentClaim::REPORT_ROLLED_BACK
            && $node->isPlayitSelfManaged()
            && $node->playit_agent_id === $claim->agent_id
            && $node->playit_previous_agent_id !== null
            && $node->playit_previous_secret !== null) {
            $this->undo($node);
            $action = 'undone';
        }

        $node->refresh();
        if (! $node->hasPlayitAgent()) {
            return ['action' => $action, 'message' => 'This node has no playit.gg agent.', 'agent_online' => null, ...$this->tunnelCounts($node, false)];
        }

        try {
            $agent = $this->client->agentOf((string) $node->playit_secret);
        } catch (PlayitException $playitException) {
            return ['action' => $action, 'message' => "playit.gg did not accept the node's agent key: ".$playitException->getMessage(), 'agent_online' => false, ...$this->tunnelCounts($node, false)];
        }

        if ($agent['agent_id'] !== $node->playit_agent_id) {
            $node->forceFill(['playit_agent_id' => $agent['agent_id']])->save();
            $action = $action === 'none' ? 'agent_corrected' : $action;
        }

        if ($node->isPlayitSelfManaged()) {
            $this->tunnels->provisionMissing($node);
        }

        $counts = $this->tunnelCounts($node, $node->isPlayitSelfManaged());
        $online = $counts['waiting_for_agent'] === 0 && ($counts['tunnels'] === 0 || $counts['created'] > 0);
        $message = match (true) {
            $action === 'finished' => 'The node had reported the switch; the panel now uses the new agent.',
            $action === 'undone' => 'The node had rolled back; the panel uses the previous agent again.',
            $action === 'agent_corrected' => 'The panel had the wrong agent id for this key; it was corrected.',
            ! $online && $node->isPlayitSelfManaged() => 'playit.gg does not see the new agent running. If the node was rolled back, choose "Undo switch"; otherwise start playit on the node.',
            default => 'The panel and the node agree.',
        };

        return ['action' => $action, 'message' => $message, 'agent_online' => $online, ...$counts];
    }

    /**
     * Make the claimed agent the node's agent: record the old agent's tunnels, keep the old key
     * for undo and cleanup, and give every server on the node a tunnel on the new agent.
     * Running it again for a node already on the new agent changes nothing.
     */
    public function finish(PlayitAgentClaim $claim): void
    {
        $claim->refresh();
        if ($claim->status === PlayitClaimStatus::Switched) {
            return;
        }

        throw_if($claim->status !== PlayitClaimStatus::Ready || $claim->secret === null || $claim->agent_id === null, DisplayException::class, 'Accept the claim on playit.gg first.');

        $node = $claim->node;
        $remoteOld = $this->oldAgentTunnels($node, $claim->agent_id);

        /** @var list<int> $reset */
        $reset = DB::transaction(function () use ($claim, $node, $remoteOld): array {
            $node = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $claim = PlayitAgentClaim::query()->whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== PlayitClaimStatus::Ready || $claim->secret === null || $claim->agent_id === null) {
                return [];
            }

            $hadOther = $node->hasPlayitAgent() && $node->playit_agent_id !== $claim->agent_id;
            if ($hadOther) {
                $this->recordOldTunnels($node, $remoteOld);
            }

            $tunnels = $this->nodeTunnels($node);

            foreach ($tunnels as $tunnel) {
                // The old agent no longer runs, so its tunnels carry nothing: start over on the new one.
                $tunnel->fill([
                    'playit_tunnel_id' => null,
                    'address' => null,
                    'status' => TunnelStatus::Pending,
                    'last_error' => null,
                    'imported_at' => null,
                ])->save();
            }

            $node->forceFill([
                'playit_previous_agent_id' => $hadOther ? $node->playit_agent_id : $node->playit_previous_agent_id,
                'playit_previous_secret' => $hadOther ? $node->playit_secret : $node->playit_previous_secret,
                'playit_previous_mode' => $hadOther ? $node->playit_mode : $node->playit_previous_mode,
                'playit_agent_id' => $claim->agent_id,
                'playit_secret' => $claim->secret,
                'playit_mode' => Node::PLAYIT_SELF_MANAGED,
                'playit_switched_at' => CarbonImmutable::now(),
            ])->save();

            $claim->fill(['status' => PlayitClaimStatus::Switched, 'last_error' => null])->save();

            return $tunnels->modelKeys();
        });

        foreach ($reset as $tunnelId) {
            dispatch(new ProvisionTunnelJob($tunnelId))->afterCommit();
        }

        $this->tunnels->provisionMissing($node->refresh());
    }

    /**
     * Put the node's previous agent back, such as after `--rollback` on the node. The tunnels the
     * panel made on the self-managed agent are deleted on playit (best effort) and the servers
     * get their old tunnels back.
     */
    public function undo(Node $node): void
    {
        $node->refresh();
        throw_if(! $node->isPlayitSelfManaged() || $node->playit_previous_agent_id === null || $node->playit_previous_secret === null, DisplayException::class, 'This node has no earlier playit.gg agent to go back to.');

        $this->deleteNewTunnels($node);

        DB::transaction(function () use ($node): void {
            $node = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            $previousAgent = (string) $node->playit_previous_agent_id;
            $readOnly = $node->playit_previous_mode === Node::PLAYIT_READ_ONLY;

            $old = PlayitOldTunnel::query()
                ->where('node_id', $node->id)
                ->where('agent_id', $previousAgent)
                ->whereNull('removed_at')
                ->whereNotNull('server_id')
                ->get()
                ->keyBy('server_id');

            $tunnels = $this->nodeTunnels($node);

            foreach ($tunnels as $tunnel) {
                $previous = $old->get($tunnel->server_id);
                if ($previous instanceof PlayitOldTunnel) {
                    $tunnel->fill([
                        'playit_tunnel_id' => $previous->playit_tunnel_id,
                        'address' => $previous->address,
                        'status' => $previous->address === null ? TunnelStatus::Pending : TunnelStatus::Active,
                        'last_error' => null,
                        'imported_at' => CarbonImmutable::now(),
                    ])->save();

                    continue;
                }

                // Nothing to go back to: the sync links a dashboard tunnel (read-only key) or
                // creates one, as before the switch.
                $tunnel->fill([
                    'playit_tunnel_id' => null,
                    'address' => null,
                    'status' => TunnelStatus::Failed,
                    'last_error' => $readOnly ? TunnelService::READ_ONLY : 'The switch to the self-managed agent was undone; the tunnel will be created again.',
                    'imported_at' => null,
                ])->save();
            }

            PlayitOldTunnel::query()->where('node_id', $node->id)->where('agent_id', $previousAgent)->delete();

            $node->forceFill([
                'playit_agent_id' => $previousAgent,
                'playit_secret' => $node->playit_previous_secret,
                'playit_mode' => $node->playit_previous_mode,
                'playit_previous_agent_id' => null,
                'playit_previous_secret' => null,
                'playit_previous_mode' => null,
                'playit_switched_at' => null,
            ])->save();

            // The claimed agent can be switched to again later with a new token.
            PlayitAgentClaim::query()
                ->where('node_id', $node->id)
                ->where('status', PlayitClaimStatus::Switched)
                ->update(['status' => PlayitClaimStatus::Ready, 'token_hash' => null, 'token_expires_at' => null]);
        });
    }

    /**
     * Delete the previous agent's tunnels on playit. Stops at the first one playit refuses because
     * the old key is read-only: those have to be deleted in the playit dashboard.
     *
     * @return array{removed: int, remaining: int, manual: bool}
     */
    public function removeOldTunnels(Node $node): array
    {
        $node->refresh();
        $pending = PlayitOldTunnel::query()->where('node_id', $node->id)->whereNull('removed_at')->orderBy('id')->get();
        $secret = $node->playit_previous_secret;
        $removed = 0;
        $manual = ! is_string($secret) || $secret === '';

        foreach ($pending as $old) {
            if ($manual || $old->agent_id !== $node->playit_previous_agent_id) {
                $manual = true;

                break;
            }

            try {
                $this->client->deleteTunnel((string) $secret, $old->playit_tunnel_id);
            } catch (PlayitException $playitException) {
                if ($playitException->reason === PlayitClient::READ_ONLY) {
                    $manual = true;

                    break;
                }

                continue;
            }

            $old->fill(['removed_at' => CarbonImmutable::now()])->save();
            $removed++;
        }

        return [
            'removed' => $removed,
            'remaining' => PlayitOldTunnel::query()->where('node_id', $node->id)->whereNull('removed_at')->count(),
            'manual' => $manual,
        ];
    }

    /** The administrator removed the old tunnels in the playit dashboard: stop listing them. */
    public function dismissOldTunnels(Node $node): int
    {
        return PlayitOldTunnel::query()->where('node_id', $node->id)->whereNull('removed_at')->update(['removed_at' => CarbonImmutable::now()]);
    }

    /**
     * Everything the node's playit card shows about the switch. Never contains a secret, the
     * claim code beyond its URL while it waits, or a token.
     *
     * @return SwitchState
     */
    public function state(Node $node): array
    {
        $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->first();
        $old = PlayitOldTunnel::query()
            ->with('server')
            ->where('node_id', $node->id)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->get();

        $script = self::scriptFile();
        $hash = is_file($script) ? hash_file('sha256', $script) : false;

        return [
            'mode' => $node->playit_mode,
            'self_managed' => $node->isPlayitSelfManaged(),
            'switched_at' => $node->playit_switched_at?->toAtomString(),
            'can_undo' => $node->isPlayitSelfManaged() && $node->playit_previous_agent_id !== null && $node->playit_previous_secret !== null,
            'claim' => $claim instanceof PlayitAgentClaim ? [
                'status' => $claim->status->value,
                'claim_url' => $claim->status->isWaiting() ? PlayitClient::CLAIM_URL.$claim->code : null,
                'expires_at' => $claim->status->isWaiting() ? $claim->expires_at?->toAtomString() : null,
                'agent_id' => $claim->agent_id,
                'token_expires_at' => $claim->token_hash !== null && $claim->fetched_at === null ? $claim->token_expires_at?->toAtomString() : null,
                'fetched_at' => $claim->fetched_at?->toAtomString(),
                'report' => $claim->report,
                'reported_at' => $claim->reported_at?->toAtomString(),
                'last_error' => $claim->last_error,
            ] : null,
            'old_tunnels' => array_values($old->map(fn (PlayitOldTunnel $tunnel): array => [
                'id' => $tunnel->id,
                'playit_tunnel_id' => $tunnel->playit_tunnel_id,
                'name' => $tunnel->name,
                'address' => $tunnel->address,
                'local_port' => $tunnel->local_port,
                'server_id' => $tunnel->server_id,
                'server_name' => $tunnel->server?->name,
            ])->all()),
            'old_tunnels_removed' => PlayitOldTunnel::query()->where('node_id', $node->id)->whereNotNull('removed_at')->count(),
            'script' => [
                'url' => mb_rtrim(config()->string('app.url'), '/').self::SCRIPT_PATH,
                'sha256' => is_string($hash) ? $hash : null,
            ],
        ];
    }

    /**
     * The node's tunnels as the switch script and "Check status" report them. With `$probe`,
     * while none was created yet one is tried right away, at most every PROBE_SECONDS.
     *
     * @return array{tunnels: int, created: int, active: int, waiting_for_agent: int, last_error: string|null}
     */
    private function tunnelCounts(Node $node, bool $probe = true): array
    {
        $tunnels = $this->nodeTunnels($node);
        if ($probe && $node->isPlayitSelfManaged() && $tunnels->whereNotNull('playit_tunnel_id')->isEmpty()) {
            $first = $tunnels->first();
            if ($first instanceof ServerTunnel && Cache::add('playit-probe:'.$node->id, true, self::PROBE_SECONDS)) {
                $this->tunnels->sync($first);
                $tunnels = $this->nodeTunnels($node);
            }
        }

        $failed = $tunnels->first(fn (ServerTunnel $tunnel): bool => $tunnel->status === TunnelStatus::Failed && $tunnel->last_error !== null);

        return [
            'tunnels' => $tunnels->count(),
            'created' => $tunnels->whereNotNull('playit_tunnel_id')->count(),
            'active' => $tunnels->where('status', TunnelStatus::Active)->count(),
            'waiting_for_agent' => $tunnels->where('last_error', TunnelService::AGENT_NOT_RUNNING)->count(),
            'last_error' => $failed instanceof ServerTunnel ? $failed->last_error : null,
        ];
    }

    /**
     * @throws PlayitException
     */
    private function advance(PlayitAgentClaim $claim): void
    {
        if ($claim->secret === null && $claim->status !== PlayitClaimStatus::Accepted) {
            try {
                $status = $this->claimStatus($this->client->claimSetup($claim->code));
            } catch (PlayitException $playitException) {
                if (in_array($playitException->reason, ['CodeExpired', 'InvalidCode'], true)) {
                    $claim->fill(['status' => PlayitClaimStatus::Expired, 'last_error' => 'playit.gg says the claim expired. Start again.'])->save();

                    return;
                }

                throw $playitException;
            }

            $claim->fill(['status' => $status, 'last_error' => null])->save();
            if ($status !== PlayitClaimStatus::Accepted) {
                return;
            }
        }

        if ($claim->secret === null) {
            try {
                $secret = $this->client->claimExchange($claim->code);
            } catch (PlayitException $playitException) {
                $outcome = match ($playitException->reason) {
                    'NotAccepted', 'NotSetup' => null,
                    'UserRejected' => PlayitClaimStatus::Rejected,
                    'CodeExpired', 'CodeNotFound' => PlayitClaimStatus::Expired,
                    default => throw $playitException,
                };

                if ($outcome instanceof PlayitClaimStatus) {
                    $claim->fill(['status' => $outcome, 'last_error' => $outcome === PlayitClaimStatus::Rejected ? 'The claim was rejected on playit.gg.' : 'playit.gg says the claim expired. Start again.'])->save();
                }

                return;
            }

            // Kept before anything else can fail: playit hands the secret out only once.
            $claim->fill(['status' => PlayitClaimStatus::Accepted, 'secret' => $secret])->save();
        }

        $agent = $this->client->agentOf((string) $claim->secret);

        // Only a self-managed agent may become the node's agent: a key for any other agent
        // (one someone else accepted the claim with, for example) is never used.
        if ($agent['self_managed'] !== true) {
            $claim->fill([
                'status' => PlayitClaimStatus::Rejected,
                'secret' => null,
                'agent_id' => null,
                'last_error' => self::NOT_SELF_MANAGED,
            ])->save();

            return;
        }

        $claim->fill(['status' => PlayitClaimStatus::Ready, 'agent_id' => $agent['agent_id'], 'last_error' => null])->save();
    }

    /**
     * The tunnel rows of the node's servers, except released ones.
     *
     * @return Collection<int, ServerTunnel>
     */
    private function nodeTunnels(Node $node): Collection
    {
        return ServerTunnel::query()
            ->whereIn('server_id', Server::query()->select('id')->where('node_id', $node->id))
            ->where('status', '!=', TunnelStatus::Deleted)
            ->orderBy('id')
            ->get();
    }

    private function claimStatus(string $answer): PlayitClaimStatus
    {
        return match ($answer) {
            PlayitClient::CLAIM_WAITING_FOR_USER => PlayitClaimStatus::WaitingForUser,
            PlayitClient::CLAIM_ACCEPTED => PlayitClaimStatus::Accepted,
            PlayitClient::CLAIM_REJECTED => PlayitClaimStatus::Rejected,
            default => PlayitClaimStatus::WaitingForVisit,
        };
    }

    private function claimForToken(Node $node, string $token): ?PlayitAgentClaim
    {
        if ($token === '') {
            return null;
        }

        $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->first();
        if (! $claim instanceof PlayitAgentClaim || ! is_string($claim->token_hash)) {
            return null;
        }

        return hash_equals($claim->token_hash, hash('sha256', $token)) ? $claim : null;
    }

    /**
     * The old agent's tunnels as playit lists them, read before the switch. Empty when the node
     * had no other agent or playit could not be reached; the linked tunnels are recorded anyway.
     *
     * @return list<array<mixed>>
     */
    private function oldAgentTunnels(Node $node, string $newAgentId): array
    {
        if (! $node->hasPlayitAgent() || $node->playit_agent_id === $newAgentId) {
            return [];
        }

        try {
            return $this->client->agentTunnels((string) $node->playit_secret, (string) $node->playit_agent_id);
        } catch (PlayitException) {
            return [];
        }
    }

    /**
     * @param  list<array<mixed>>  $remote
     */
    private function recordOldTunnels(Node $node, array $remote): void
    {
        $agentId = (string) $node->playit_agent_id;
        $linked = ServerTunnel::query()
            ->with('server:id,node_id,allocation_id', 'server.allocation:id,port', 'allocation:id,port')
            ->whereIn('server_id', Server::query()->select('id')->where('node_id', $node->id))
            ->whereNotNull('playit_tunnel_id')
            ->get()
            ->keyBy('playit_tunnel_id');

        foreach ($linked as $tunnelId => $tunnel) {
            PlayitOldTunnel::query()->updateOrCreate(
                ['node_id' => $node->id, 'playit_tunnel_id' => (string) $tunnelId],
                [
                    'agent_id' => $agentId,
                    'server_id' => $tunnel->server_id,
                    'address' => $tunnel->address,
                    'local_port' => $tunnel->allocation->port ?? $tunnel->server?->allocation?->port,
                    'removed_at' => null,
                ],
            );
        }

        foreach ($remote as $tunnel) {
            $id = $tunnel['id'] ?? null;
            if (! is_string($id) || preg_match(PlayitClient::UUID_PATTERN, $id) !== 1) {
                continue;
            }

            $name = $tunnel['name'] ?? null;
            $existing = PlayitOldTunnel::query()->where('node_id', $node->id)->where('playit_tunnel_id', $id)->first();
            PlayitOldTunnel::query()->updateOrCreate(
                ['node_id' => $node->id, 'playit_tunnel_id' => $id],
                [
                    'agent_id' => $agentId,
                    'server_id' => $existing->server_id ?? $linked->get($id)?->server_id,
                    'name' => is_string($name) ? mb_substr($name, 0, 255) : null,
                    'address' => PlayitClient::addressOf($tunnel) ?? $existing?->address,
                    'local_port' => PlayitClient::localPort($tunnel) ?? $existing?->local_port,
                    'removed_at' => null,
                ],
            );
        }
    }

    /** The tunnels the panel made on the self-managed agent, deleted when the switch is undone. */
    private function deleteNewTunnels(Node $node): void
    {
        $secret = (string) $node->playit_secret;
        $made = ServerTunnel::query()
            ->whereIn('server_id', Server::query()->select('id')->where('node_id', $node->id))
            ->whereNotNull('playit_tunnel_id')
            ->whereNull('imported_at')
            ->pluck('playit_tunnel_id');

        foreach ($made as $tunnelId) {
            if (! is_string($tunnelId)) {
                continue;
            }

            try {
                $this->client->deleteTunnel($secret, $tunnelId);
            } catch (PlayitException) {
                // Left on playit; the administrator can delete it in the dashboard.
            }
        }
    }
}
