<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;

/**
 * A message in a conversation: what the user wrote, what the model answered (with the tool
 * calls it asked for), or the result of one tool call.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string|null $content
 * @property list<array{id: string, type: string, function: array{name: string, arguments: string}}>|null $tool_calls
 * @property string|null $tool_call_id
 * @property string|null $notice "stopped" for the note a stop leaves in the thread
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property CarbonImmutable|null $created_at
 * @property-read AiConversation $conversation
 */
#[Fillable([
    'conversation_id',
    'role',
    'content',
    'tool_calls',
    'tool_call_id',
    'notice',
    'prompt_tokens',
    'completion_tokens',
])]
#[RouteKey('id')]
#[Table(name: 'ai_messages')]
class AiMessage extends Model
{
    public const string RESOURCE_NAME = 'ai_message';

    public const string ROLE_USER = 'user';

    public const string ROLE_ASSISTANT = 'assistant';

    public const string ROLE_TOOL = 'tool';

    /** The note a stop leaves in the thread, an assistant message with this notice. */
    public const string NOTICE_STOPPED = 'stopped';

    public const null UPDATED_AT = null;

    protected $attributes = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation_id' => 'int',
            'tool_calls' => 'array',
            'prompt_tokens' => 'int',
            'completion_tokens' => 'int',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * @return HasMany<AiToolCall, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(AiToolCall::class, 'message_id');
    }
}
