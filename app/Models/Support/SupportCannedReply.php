<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Support;

use Carbon\CarbonImmutable;
use Database\Factories\Support\SupportCannedReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Pterodactyl\Models\Model;

/**
 * A saved answer staff insert into a reply, such as how to reset a world.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('support_canned_replies')]
#[Fillable(['title', 'body'])]
#[RouteKey('id')]
class SupportCannedReply extends Model
{
    /** @use HasFactory<SupportCannedReplyFactory> */
    use HasFactory;
}
