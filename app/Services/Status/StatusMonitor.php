<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Status;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Status\StatusCheck;
use Pterodactyl\Models\Status\StatusDay;
use Pterodactyl\Services\Insights\NodeUsageService;
use Throwable;

/**
 * Takes the once-a-minute status checks: the panel's own health (database and cache, plus
 * how long its public status endpoint takes to answer) and whether each node on the status
 * page answers Wings' system information. Every check is one cheap call with a short
 * timeout, and the nodes are asked at once.
 *
 * @phpstan-type Check array{target: string, ok: bool, response_ms: int|null}
 */
final readonly class StatusMonitor
{
    private const string PROBE_KEY = 'status:probe';

    /** The panel's own round trip gives up after this long; a timeout is no reading, not an outage. */
    private const int PROBE_TIMEOUT_SECONDS = 5;

    private const int PROBE_CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(private NodeUsageService $usage) {}

    /**
     * Takes and records one round of checks; nothing when the status page is off.
     *
     * @return list<Check>
     */
    public function run(): array
    {
        if (! StatusPageSettings::enabled()) {
            return [];
        }

        $checks = [$this->panel()];

        $nodes = array_map(fn (array $row): Node => $row['node'], StatusPageSettings::visibleNodes());
        foreach ($this->usage->reachability($nodes) as $id => $reachable) {
            $checks[] = ['target' => StatusCheck::nodeTarget($id), 'ok' => $reachable, 'response_ms' => null];
        }

        $this->record($checks, CarbonImmutable::now());

        return $checks;
    }

    /**
     * @param  list<Check>  $checks
     */
    public function record(array $checks, CarbonImmutable $at): void
    {
        if ($checks === []) {
            return;
        }

        $date = $at->toDateString();

        DB::transaction(function () use ($checks, $at, $date): void {
            StatusCheck::query()->insert(array_map(fn (array $check): array => [...$check, 'checked_at' => $at], $checks));

            StatusDay::query()->upsert(
                array_map(fn (array $check): array => [
                    'target' => $check['target'],
                    'date' => $date,
                    'checks' => 1,
                    'successes' => $check['ok'] ? 1 : 0,
                    'response_ms_total' => $check['response_ms'] ?? 0,
                    'response_samples' => $check['response_ms'] === null ? 0 : 1,
                ], $checks),
                ['target', 'date'],
                [
                    'checks' => DB::raw('checks + 1'),
                    'successes' => DB::raw('successes + VALUES(successes)'),
                    'response_ms_total' => DB::raw('response_ms_total + VALUES(response_ms_total)'),
                    'response_samples' => DB::raw('response_samples + VALUES(response_samples)'),
                ],
            );
        });
    }

    /**
     * The panel is up while its database and cache work and it does not answer its own
     * status endpoint with a server error. That round trip is also its response time; when
     * it cannot be made at all (no route to the public address from this host), there is
     * no reading rather than an outage.
     *
     * @return Check
     */
    private function panel(): array
    {
        try {
            DB::select('select 1');
            $token = Str::random(16);
            Cache::put(self::PROBE_KEY, $token, 120);
            $healthy = Cache::get(self::PROBE_KEY) === $token;
        } catch (Throwable) {
            $healthy = false;
        }

        $milliseconds = null;
        $start = hrtime(true);

        try {
            $response = Http::acceptJson()
                ->timeout(self::PROBE_TIMEOUT_SECONDS)
                ->connectTimeout(self::PROBE_CONNECT_TIMEOUT_SECONDS)
                ->get(route('api.public.status'));

            $elapsed = (int) round((hrtime(true) - $start) / 1_000_000);
            if ($response->serverError()) {
                $healthy = false;
            } elseif ($response->successful()) {
                $milliseconds = max(0, $elapsed);
            }
        } catch (Throwable) {
            // Unreachable from here; see above.
        }

        return ['target' => StatusCheck::PANEL, 'ok' => $healthy, 'response_ms' => $milliseconds];
    }
}
