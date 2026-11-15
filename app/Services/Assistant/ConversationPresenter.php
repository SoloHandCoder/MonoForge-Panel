<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Illuminate\Support\Str;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;

/**
 * The JSON the chat UI reads. Tool results are part of their tool call (as a preview), so
 * the "tool" messages the model reads are not listed.
 */
class ConversationPresenter
{
    public const int RESULT_PREVIEW_CHARS = 1000;

    /** Documentation examples of the payloads below. */
    public const array EXAMPLE = [
        'uuid' => '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93',
        'title' => 'Why does my server crash on start?',
        'status' => 'awaiting_approval',
        'error' => null,
        'messages' => [
            [
                'id' => 1,
                'role' => 'user',
                'content' => 'Why does my server crash on start?',
                'created_at' => '2026-11-18T12:00:00+00:00',
                'tool_calls' => [],
            ],
            [
                'id' => 2,
                'role' => 'assistant',
                'content' => 'Let me look at the console first.',
                'created_at' => '2026-11-18T12:00:03+00:00',
                'tool_calls' => [
                    [
                        'id' => 1,
                        'name' => 'read_console',
                        'label' => 'Read the last 100 console lines',
                        'arguments' => ['lines' => 100],
                        'needs_confirmation' => false,
                        'status' => 'done',
                        'result_preview' => '[12:00:01 ERROR]: Could not load plugin.yml',
                        'display' => ['lines' => 100],
                    ],
                    [
                        'id' => 2,
                        'name' => 'write_file',
                        'label' => 'Write plugins/Example/config.yml',
                        'arguments' => ['path' => 'plugins/Example/config.yml', 'content' => 'enabled: true'],
                        'needs_confirmation' => true,
                        'status' => 'pending',
                        'result_preview' => null,
                        'display' => null,
                    ],
                ],
            ],
        ],
        'usage' => ['free_used' => 12000, 'free_limit' => 50000, 'charged_this_month' => 0],
    ];

    /** The OpenAPI schema of `messages`, which an example alone cannot express (nullable fields, enums). */
    public const array MESSAGES_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'role', 'content', 'created_at', 'tool_calls'],
            'properties' => [
                'id' => ['type' => 'integer', 'example' => 2],
                'role' => ['type' => 'string', 'enum' => ['user', 'assistant'], 'example' => 'assistant'],
                'content' => ['type' => 'string', 'nullable' => true, 'example' => 'Let me look at the console first.'],
                'created_at' => ['type' => 'string', 'nullable' => true, 'example' => '2026-11-18T12:00:03+00:00'],
                'tool_calls' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'label', 'arguments', 'needs_confirmation', 'status', 'result_preview', 'display'],
                        'properties' => [
                            'id' => ['type' => 'integer', 'example' => 2],
                            'name' => ['type' => 'string', 'example' => 'write_file'],
                            'label' => ['type' => 'string', 'example' => 'Write plugins/Example/config.yml'],
                            'arguments' => ['type' => 'object', 'additionalProperties' => true, 'example' => ['path' => 'plugins/Example/config.yml']],
                            'needs_confirmation' => ['type' => 'boolean', 'example' => true],
                            'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'denied', 'running', 'done', 'failed'], 'example' => 'pending'],
                            'result_preview' => ['type' => 'string', 'nullable' => true, 'example' => null],
                            'display' => ['type' => 'object', 'nullable' => true, 'additionalProperties' => true, 'example' => null],
                        ],
                    ],
                ],
            ],
        ],
    ];

    public const array STATUS_SCHEMA = ['type' => 'string', 'enum' => ['idle', 'running', 'awaiting_approval', 'error'], 'example' => 'awaiting_approval'];

    public const array LIST_EXAMPLE = [
        'data' => [[
            'uuid' => '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93',
            'title' => 'Why does my server crash on start?',
            'status' => 'idle',
            'updated_at' => '2026-11-18T12:01:00+00:00',
        ]],
    ];

    public function __construct(private readonly AssistantBilling $billing) {}

    /**
     * @return array{uuid: string, title: string, status: string, updated_at: string|null}
     */
    public static function summary(AiConversation $conversation): array
    {
        return [
            'uuid' => $conversation->uuid,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'updated_at' => $conversation->updated_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCall(AiToolCall $call): array
    {
        return [
            'id' => $call->id,
            'name' => $call->name,
            'label' => $call->label ?? $call->name,
            'arguments' => $call->arguments === [] ? (object) [] : $call->arguments,
            'needs_confirmation' => $call->needs_confirmation,
            'status' => $call->status,
            'result_preview' => $call->result === null ? null : Str::limit($call->result, self::RESULT_PREVIEW_CHARS),
            'display' => $call->display === null || $call->display === [] ? null : $call->display,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AiConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->where('role', '!=', AiMessage::ROLE_TOOL)
            ->with('calls')
            ->orderBy('id')
            ->get();

        return [
            'uuid' => $conversation->uuid,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'error' => $conversation->error,
            'messages' => $messages->map(fn (AiMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toAtomString(),
                'tool_calls' => $message->calls->sortBy('id')->map(fn (AiToolCall $call): array => self::toolCall($call))->values()->all(),
            ])->values()->all(),
            'usage' => [
                'free_used' => min(AssistantSettings::freeTokensMonthly(), $this->billing->freeUsed($conversation->user)),
                'free_limit' => AssistantSettings::freeTokensMonthly(),
                'charged_this_month' => $this->billing->chargedThisMonth($conversation->user),
            ],
        ];
    }
}
