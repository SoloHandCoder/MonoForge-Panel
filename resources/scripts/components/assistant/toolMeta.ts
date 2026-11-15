import {
    Activity,
    Archive,
    Ban,
    CalendarClock,
    Coins,
    FilePen,
    FileSearch,
    FileText,
    FolderOpen,
    Gauge,
    HardDrive,
    KeyRound,
    Megaphone,
    Network,
    PencilLine,
    Power,
    Rocket,
    Search,
    Server,
    ShieldCheck,
    SquareTerminal,
    Store,
    Trash2,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import type { AssistantToolCall, AssistantToolCallStatus } from '@/api/assistant';
import type { UiTone } from '@/themes/skins';

const icons = new Map<string, LucideIcon>([
    ['server_status', Gauge],
    ['read_console', SquareTerminal],
    ['send_command', SquareTerminal],
    ['list_files', FolderOpen],
    ['read_file', FileText],
    ['search_files', FileSearch],
    ['write_file', FilePen],
    ['edit_file', FilePen],
    ['delete_files', Trash2],
    ['rename_file', PencilLine],
    ['power', Power],
    ['list_backups', Archive],
    ['create_backup', Archive],
    ['list_schedules', CalendarClock],
    ['get_startup', Rocket],
    ['reveal_startup_variable', KeyRound],
    ['update_startup_variable', Rocket],
    ['set_docker_image', Rocket],
    ['get_network', Network],
    ['list_servers', Server],
    ['server_overview', Server],
    ['list_users', Users],
    ['node_usage', HardDrive],
    ['search_activity', Activity],
    ['shop_overview', Store],
    ['suspend_server', Ban],
    ['unsuspend_server', ShieldCheck],
    ['create_announcement', Megaphone],
    ['adjust_tokens', Coins],
]);

/** The icon of a tool, by its name. */
export const toolIcon = (name: string): LucideIcon => icons.get(name) ?? (name.startsWith('search') ? Search : Wrench);

/**
 * Tools that change or destroy something, so a pending call gets the red warning card. Everything
 * else that needs a click (such as reading a sensitive file) gets the amber one.
 */
const destructiveTools = new Set([
    'write_file',
    'edit_file',
    'delete_files',
    'rename_file',
    'send_command',
    'power',
    'update_startup_variable',
    'set_docker_image',
    'suspend_server',
    'adjust_tokens',
]);

export const isDestructiveTool = (name: string): boolean => destructiveTools.has(name);

/** A tool that reads something sensitive, such as an `.env` file. */
export const isSensitiveRead = (call: Pick<AssistantToolCall, 'name' | 'needs_confirmation'>): boolean =>
    call.needs_confirmation && !isDestructiveTool(call.name) && call.name.startsWith('read');

export const sensitiveIcon = KeyRound;

/** Why a pending call needs a click, in a sentence. */
export function confirmationWarning(call: Pick<AssistantToolCall, 'name' | 'needs_confirmation'>): string {
    switch (call.name) {
        case 'delete_files':
            return 'This permanently deletes files from the server. It cannot be undone.';
        case 'write_file':
            return 'This overwrites the file on the server.';
        case 'edit_file':
            return 'This changes the listed text in the file on the server. Lines with hidden values are never touched.';
        case 'rename_file':
            return 'This moves or renames files on the server.';
        case 'power':
            return 'This changes the power state of the server. Players may be disconnected.';
        case 'send_command':
            return 'This runs a command in the server console.';
        case 'update_startup_variable':
            return 'This changes how the server starts.';
        case 'set_docker_image':
            return 'This changes the Docker image (such as the Java version) the server starts with.';
        case 'suspend_server':
            return 'This suspends the server: its owner loses access until it is unsuspended.';
        case 'adjust_tokens':
            return "This changes a user's token balance.";
        case 'reveal_startup_variable':
            return 'This shows you the value of a startup variable that may be a secret. The assistant does not see it.';
        default:
            return isSensitiveRead(call)
                ? 'This file may contain secrets such as passwords or keys. You see the full file; the assistant and the AI provider get a copy with secret values hidden.'
                : 'The assistant needs your permission for this action.';
    }
}

export const toolStatusLabels = {
    pending: 'Waiting for you',
    approved: 'Allowed',
    denied: 'Denied',
    running: 'Running',
    done: 'Done',
    failed: 'Failed',
} satisfies Record<AssistantToolCallStatus, string>;

export const toolStatusTones = {
    pending: 'warning',
    approved: 'info',
    denied: 'neutral',
    running: 'info',
    done: 'success',
    failed: 'danger',
} satisfies Record<AssistantToolCallStatus, UiTone>;
