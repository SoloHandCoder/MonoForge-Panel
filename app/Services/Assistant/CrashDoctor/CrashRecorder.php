<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\CrashDoctor;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\ReadsServerLogs;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;
use Pterodactyl\Services\Assistant\Tools\Server\ReadFileTool;
use Throwable;

/**
 * Records crashes for the crash doctor and knows which stops were asked for.
 *
 * A stop, restart or kill asked for through the panel API, a schedule or the assistant is
 * remembered for a few minutes ({@see expectPowerChange()}). Those sent through the console's
 * websocket go straight to Wings, which reports them back as activity (server:power.*, or the
 * egg's stop command as server:console.command) about a minute later; the detector waits for
 * that before it decides.
 */
class CrashRecorder
{
    /** A second crash of a server within this time is the same incident. */
    public const int DEDUPE_MINUTES = 10;

    /** How long a power change asked for through the panel is remembered. */
    public const int POWER_MEMORY_MINUTES = 10;

    /** The console lines kept with a crash, at most this many bytes of their end. */
    public const int CONSOLE_BYTES = 32_768;

    /** Commands that stop most game servers, beside the egg's own stop command. */
    private const array STOP_COMMANDS = ['stop', 'end', 'exit', 'quit', 'shutdown', 'save-quit'];

    private const array STOP_EVENTS = ['server:power.stop', 'server:power.restart', 'server:power.kill'];

    public function __construct(private readonly ReadsServerLogs $logs) {}

    public static function powerKey(Server $server): string
    {
        return "crash-doctor:power:{$server->uuid}";
    }

    /**
     * Remembers that a power signal (or the stop command) was sent through the panel, so the
     * server going offline, or not coming up, is not taken for a crash.
     */
    public static function expectPowerChange(Server $server, string $signal): void
    {
        Cache::put(self::powerKey($server), ['signal' => mb_strtolower($signal), 'at' => CarbonImmutable::now()->getTimestamp()], now()->addMinutes(self::POWER_MEMORY_MINUTES));
    }

    /** Whether the command is one that stops the server. */
    public static function isStopCommand(Server $server, string $command): bool
    {
        $command = mb_strtolower(mb_ltrim(mb_trim($command), '/'));
        $configured = $server->egg?->inherit_config_stop;
        $configured = is_string($configured) ? mb_strtolower(mb_ltrim(mb_trim($configured), '/')) : null;

        return $command !== '' && ($command === $configured || in_array($command, self::STOP_COMMANDS, true));
    }

    /**
     * When a start was last asked for through the panel, if it was the latest power change.
     */
    public function startRequestedAt(Server $server): ?CarbonImmutable
    {
        $marker = $this->marker($server);

        return $marker !== null && $marker['signal'] === 'start' ? CarbonImmutable::createFromTimestamp($marker['at']) : null;
    }

    /**
     * Whether a stop, restart or kill (or the stop command) was asked for since then, through
     * the panel or through the console.
     */
    public function stopRequestedSince(Server $server, CarbonImmutable $since): bool
    {
        $marker = $this->marker($server);
        if ($marker !== null && $marker['signal'] !== 'start' && $marker['at'] >= $since->getTimestamp()) {
            return true;
        }

        $events = $server->activity()
            ->whereIn('activity_logs.event', [...self::STOP_EVENTS, 'server:console.command'])
            ->where('activity_logs.timestamp', '>=', $since)
            ->without('subjects')
            ->get(['activity_logs.id', 'activity_logs.event', 'activity_logs.properties']);

        return $events->contains(function (ActivityLog $log) use ($server): bool {
            $command = $log->properties->get('command');

            return in_array($log->event, self::STOP_EVENTS, true) || (is_string($command) && self::isStopCommand($server, $command));
        });
    }

    /**
     * Stores the crash with the last console lines, masked. Nothing new is stored (null) when
     * the server crashed less than DEDUPE_MINUTES ago.
     */
    public function record(Server $server, string $kind, string $source): ?ServerCrash
    {
        $recent = ServerCrash::query()
            ->where('server_id', $server->id)
            ->where('detected_at', '>=', CarbonImmutable::now()->subMinutes(self::DEDUPE_MINUTES))
            ->exists();

        if ($recent) {
            return null;
        }

        return ServerCrash::query()->create([
            'server_id' => $server->id,
            'kind' => $kind,
            'source' => $source,
            'detected_at' => CarbonImmutable::now(),
            'console' => $this->console($server),
            'status' => ServerCrash::STATUS_OPEN,
        ]);
    }

    /**
     * The crash the server page offers the doctor for: the newest of the last day, unless it
     * was dismissed.
     */
    public function latest(Server $server): ?ServerCrash
    {
        $crash = ServerCrash::query()
            ->where('server_id', $server->id)
            ->where('detected_at', '>=', CarbonImmutable::now()->subHours(ServerCrash::BANNER_HOURS))
            ->latest('detected_at')
            ->latest('id')
            ->first();

        return $crash instanceof ServerCrash && $crash->status !== ServerCrash::STATUS_DISMISSED ? $crash : null;
    }

    /**
     * The last console lines without colour codes, secrets and addresses, or null when Wings
     * cannot be read.
     */
    public function console(Server $server): ?string
    {
        try {
            $lines = $this->logs->read($server, ReadsServerLogs::MAX_LINES);
        } catch (Throwable) {
            return null;
        }

        $text = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', implode("\n", $lines)) ?? '';
        if ($text === '') {
            return null;
        }

        $masked = (new OutputRedactor)->redact(ReadFileTool::forModel(SecretRedactor::redactLog($text)));

        return ReadFileTool::tail($masked, ReadsServerLogs::MAX_LINES, self::CONSOLE_BYTES);
    }

    /**
     * @return array{signal: string, at: int}|null
     */
    private function marker(Server $server): ?array
    {
        $marker = Cache::get(self::powerKey($server));

        return is_array($marker) && is_string($marker['signal'] ?? null) && is_int($marker['at'] ?? null)
            ? ['signal' => $marker['signal'], 'at' => $marker['at']]
            : null;
    }
}
