<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\CrashDoctor;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Assistant\Chat\ChatToolCall;
use Pterodactyl\Services\Assistant\ConversationService;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolExecutor;
use Pterodactyl\Services\Assistant\ToolRegistry;
use Pterodactyl\Services\Assistant\Tools\Server\ReadConsoleTool;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Starts a crash doctor conversation: a server assistant conversation in the "crash_doctor"
 * mode, whose first assistant step reads the console, the newest crash report and the end of
 * logs/latest.log with the ordinary tools. Those calls run in the queued turn before the model
 * is asked, with the user's permissions and the tools' masking; a reading the user may not do
 * is left out. Billing, the free allowance and the busy rules are those of any conversation.
 */
class CrashDiagnosis
{
    public const string TITLE = 'Crash diagnosis';

    /** The console and the log are read this far back. */
    public const int LOG_LINES = 200;

    public const string CRASH_REPORTS = '/crash-reports';

    public const string LATEST_LOG = '/logs/latest.log';

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly ListsDirectories $lister,
    ) {}

    /**
     * @throws AssistantUnavailableException when the crash doctor or the assistant is off, or nothing can be paid
     * @throws ConflictHttpException while another conversation of the user is running
     */
    public function start(User $user, Server $server, ?ServerCrash $crash): AiConversation
    {
        throw_unless(AssistantSettings::crashDoctorAvailable(), AssistantUnavailableException::class, 'The AI crash doctor is turned off.');

        $conversation = $this->conversations->start(
            $user,
            AiConversation::SCOPE_SERVER,
            $server,
            $this->message($crash),
            AiConversation::MODE_CRASH_DOCTOR,
            self::TITLE,
            fn (AiConversation $conversation) => $this->preload($conversation, $server),
        );

        if ($crash instanceof ServerCrash) {
            $crash->forceFill(['status' => ServerCrash::STATUS_DIAGNOSING, 'conversation_id' => $conversation->id])->save();
        }

        return $conversation;
    }

    private function message(?ServerCrash $crash): string
    {
        if (! $crash instanceof ServerCrash) {
            return 'Please check my server: why does it crash or fail to start, and how do I fix it?';
        }

        $when = $crash->detected_at->toDayDateTimeString().' (UTC'.$crash->detected_at->format('P').')';

        return $crash->kind === ServerCrash::KIND_START_FAILED
            ? "My server failed to start ({$when}). What happened, and how do I fix it?"
            : "My server crashed ({$when}). What happened, and how do I fix it?";
    }

    /**
     * Records the assistant step that reads the logs. Only tools the user may use are called.
     */
    private function preload(AiConversation $conversation, Server $server): void
    {
        $context = ToolExecutor::context($conversation);
        $calls = [];

        if ($this->registry->find('read_console', $context) instanceof AssistantTool) {
            $calls[] = new ChatToolCall('preload_console', 'read_console', ['lines' => ReadConsoleTool::MAX_LINES], '{"lines":'.ReadConsoleTool::MAX_LINES.'}');
        }

        if ($this->registry->find('read_file', $context) instanceof AssistantTool) {
            $report = $this->newestCrashReport($server, $context);
            if ($report !== null) {
                $calls[] = new ChatToolCall('preload_crash_report', 'read_file', ['path' => $report], (string) json_encode(['path' => $report], JSON_UNESCAPED_SLASHES));
            }

            $calls[] = new ChatToolCall('preload_latest_log', 'read_file', ['path' => self::LATEST_LOG, 'tail_lines' => self::LOG_LINES], (string) json_encode(['path' => self::LATEST_LOG, 'tail_lines' => self::LOG_LINES], JSON_UNESCAPED_SLASHES));
        }

        if ($calls === []) {
            return;
        }

        $message = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => 'First I read the console, the newest crash report and the end of the latest log.',
            'tool_calls' => array_map(fn (ChatToolCall $call): array => [
                'id' => $call->id,
                'type' => 'function',
                'function' => ['name' => $call->name, 'arguments' => $call->rawArguments],
            ], $calls),
        ]);

        $this->executor->record($conversation, $message, $calls);
    }

    /**
     * The path of the newest file in /crash-reports, when the user may list files and there is one.
     */
    private function newestCrashReport(Server $server, ToolContext $context): ?string
    {
        if (! $this->registry->find('list_files', $context) instanceof AssistantTool) {
            return null;
        }

        try {
            $entries = $this->lister->list($server, self::CRASH_REPORTS, 5);
        } catch (Throwable) {
            return null;
        }

        $newest = null;
        $newestAt = null;
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? null;
            if (! is_string($name) || ! ($entry['file'] ?? false) || ($entry['symlink'] ?? false) || ! preg_match('/\.(txt|log)$/i', $name)) {
                continue;
            }

            try {
                $at = CarbonImmutable::parse((string) ($entry['modified'] ?? ''));
            } catch (Throwable) {
                continue;
            }

            if (! $newestAt instanceof CarbonImmutable || $at->gt($newestAt)) {
                [$newest, $newestAt] = [$name, $at];
            }
        }

        return $newest === null ? null : self::CRASH_REPORTS.'/'.$newest;
    }
}
