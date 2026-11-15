<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\User;

/**
 * One tool call the model asked for, with its arguments, whether a human has to allow it,
 * and its result once it ran.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $message_id
 * @property string $call_id
 * @property string $name
 * @property string|null $label
 * @property array<string, mixed> $arguments
 * @property list<array{label: string, value: string}>|null $details
 * @property bool $needs_confirmation
 * @property string $status
 * @property string|null $result
 * @property string|null $private_result
 * @property array<string, mixed>|null $display
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AiConversation $conversation
 * @property-read AiMessage $message
 */
#[Fillable([
    'conversation_id',
    'message_id',
    'call_id',
    'name',
    'label',
    'arguments',
    'details',
    'needs_confirmation',
    'status',
    'result',
    'private_result',
    'display',
    'decided_by',
    'decided_at',
])]
#[Hidden(['private_result'])]
#[RouteKey('id')]
#[Table(name: 'ai_tool_calls')]
class AiToolCall extends Model
{
    public const string RESOURCE_NAME = 'ai_tool_call';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_DENIED = 'denied';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_DONE = 'done';

    public const string STATUS_FAILED = 'failed';

    /** Stored results are cut to 16 KB. */
    public const int MAX_RESULT_BYTES = 16384;

    /** A call nobody decided on within this time can no longer be allowed. */
    public const int EXPIRES_AFTER_MINUTES = 30;

    protected $attributes = [
        'needs_confirmation' => false,
        'status' => self::STATUS_PENDING,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation_id' => 'int',
            'message_id' => 'int',
            'arguments' => 'array',
            'details' => 'array',
            'private_result' => 'encrypted',
            'needs_confirmation' => 'bool',
            'display' => 'array',
            'decided_by' => 'int',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Whether the call waited for a decision for so long that it can no longer be allowed. */
    public function isExpired(): bool
    {
        return $this->created_at instanceof CarbonImmutable
            && $this->created_at->lt(CarbonImmutable::now()->subMinutes(self::EXPIRES_AFTER_MINUTES));
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<AiMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
