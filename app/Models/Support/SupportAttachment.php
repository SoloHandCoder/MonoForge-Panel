<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;

/**
 * An image on a support message. The file lives on the private disk at `path` and is only
 * served through the ticket endpoints to people who can see the message.
 *
 * @property int $id
 * @property int $message_id
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 * @property CarbonImmutable|null $created_at
 * @property-read SupportMessage $message
 */
#[Table('support_attachments')]
#[Fillable(['path', 'name', 'mime', 'size', 'width', 'height'])]
#[RouteKey('id')]
class SupportAttachment extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_id' => 'int',
            'size' => 'int',
            'width' => 'int',
            'height' => 'int',
        ];
    }

    /**
     * @return BelongsTo<SupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
