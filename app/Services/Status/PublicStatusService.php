<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Status;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\Status\StatusCheck;
use Pterodactyl\Models\Status\StatusDay;
use Pterodactyl\Support\LockedCache;

/**
 * What the public status page shows, built from the recorded checks and cached for 30
 * seconds so viewers never reach Wings. Nodes appear by display name only: no ID, FQDN,
 * address or panel name of a node is in it.
 *
 * @phpstan-type StatusDayRow array{date: string, uptime: float|null, response_ms: int|null}
 * @phpstan-type StatusComponent array{name: string, status: 'operational'|'outage'|'unknown', uptime: float|null, days: list<StatusDayRow>}
 * @phpstan-type StatusIncident array{id: int, title: string, body: string, level: string, started_at: string|null}
 * @phpstan-type StatusPanel array{name: string, status: 'operational'|'outage'|'unknown', uptime: float|null, days: list<StatusDayRow>, response_ms: int|null}
 * @phpstan-type StatusPayload array{state: 'operational'|'partial_outage'|'major_outage', checked_at: string|null, panel: StatusPanel, nodes: list<StatusComponent>, incidents: list<StatusIncident>}
 */
final class PublicStatusService
{
    public const string CACHE_KEY = 'status:public';

    public const int CACHE_SECONDS = 30;

    /** How many daily bars each target has. */
    public const int DAYS = 90;

    /** With no check this recent the checks have stopped, and a target's state is unknown. */
    public const int STALE_MINUTES = 5;

    /** @return StatusPayload */
    public function payload(): array
    {
        /** @var StatusPayload */
        return LockedCache::remember(Cache::store(), self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->build());
    }

    /** @return StatusPayload */
    public function build(): array
    {
        $now = CarbonImmutable::now();
        $nodes = StatusPageSettings::visibleNodes();
        $targets = [StatusCheck::PANEL, ...array_map(fn (array $row): string => StatusCheck::nodeTarget($row['node']->id), $nodes)];

        /** @var array<string, StatusCheck> $latest */
        $latest = [];
        $recent = StatusCheck::query()
            ->whereIn('target', $targets)
            ->where('checked_at', '>=', $now->subMinutes(self::STALE_MINUTES))
            ->orderBy('id')
            ->get();
        foreach ($recent as $check) {
            $latest[$check->target] = $check;
        }

        $first = $now->subDays(self::DAYS - 1)->startOfDay();
        /** @var array<string, array<string, StatusDay>> $days */
        $days = [];
        $rows = StatusDay::query()
            ->whereIn('target', $targets)
            ->where('date', '>=', $first->toDateString())
            ->get();
        foreach ($rows as $row) {
            $days[$row->target][$row->date->toDateString()] = $row;
        }

        $panelCheck = $latest[StatusCheck::PANEL] ?? null;
        $panel = [
            ...$this->component('Panel', $panelCheck, $days[StatusCheck::PANEL] ?? [], $first),
            'response_ms' => $panelCheck?->response_ms,
        ];

        $components = array_map(function (array $row) use ($latest, $days, $first): array {
            $target = StatusCheck::nodeTarget($row['node']->id);

            return $this->component($row['name'], $latest[$target] ?? null, $days[$target] ?? [], $first);
        }, $nodes);

        return [
            'state' => $this->state($panel['status'], array_column($components, 'status')),
            'checked_at' => $panelCheck?->checked_at->toAtomString(),
            'panel' => $panel,
            'nodes' => $components,
            'incidents' => $this->incidents(),
        ];
    }

    /**
     * @param  array<string, StatusDay>  $days  by date
     * @return StatusComponent
     */
    private function component(string $name, ?StatusCheck $latest, array $days, CarbonImmutable $first): array
    {
        $bars = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $date = $first->addDays($i)->toDateString();
            $day = $days[$date] ?? null;
            $bars[] = [
                'date' => $date,
                'uptime' => $day === null ? null : $this->percentage($day->successes, $day->checks),
                'response_ms' => $day === null || $day->response_samples === 0 ? null : (int) round($day->response_ms_total / $day->response_samples),
            ];
        }

        $checks = array_sum(array_map(fn (StatusDay $day): int => $day->checks, $days));
        $successes = array_sum(array_map(fn (StatusDay $day): int => $day->successes, $days));

        return [
            'name' => $name,
            'status' => match (true) {
                ! $latest instanceof StatusCheck => 'unknown',
                $latest->ok => 'operational',
                default => 'outage',
            },
            'uptime' => $this->percentage($successes, $checks),
            'days' => $bars,
        ];
    }

    private function percentage(int $successes, int $checks): ?float
    {
        return $checks === 0 ? null : round($successes / $checks * 100, 2);
    }

    /**
     * The panel down is a major outage, and so is every node down; one node down is partial.
     * A target whose checks stopped is left out.
     *
     * @param  list<string>  $nodes
     * @return 'operational'|'partial_outage'|'major_outage'
     */
    private function state(string $panel, array $nodes): string
    {
        $known = array_values(array_filter($nodes, fn (string $status): bool => $status !== 'unknown'));
        $down = count(array_filter($known, fn (string $status): bool => $status === 'outage'));

        return match (true) {
            $panel === 'outage', $known !== [] && $down === count($known) => 'major_outage',
            $down > 0 => 'partial_outage',
            default => 'operational',
        };
    }

    /**
     * The announcements showing now that warn of a problem.
     *
     * @return list<StatusIncident>
     */
    private function incidents(): array
    {
        $incidents = [];
        foreach (Announcement::showing() as $announcement) {
            if (! in_array($announcement->level, [NoticeLevel::Warning, NoticeLevel::Danger], true)) {
                continue;
            }

            $incidents[] = [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'level' => $announcement->level->value,
                'started_at' => ($announcement->starts_at ?? $announcement->created_at)?->toAtomString(),
            ];
        }

        return $incidents;
    }
}
