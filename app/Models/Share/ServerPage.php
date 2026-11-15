<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Share;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;

/**
 * A server's shareable public page at /s/{slug}, set up by its owner or a subuser with
 * `share.manage`, and shown to anyone only while it is published.
 *
 * @property int $id
 * @property int $server_id
 * @property string $slug
 * @property bool $published
 * @property bool $noindex
 * @property string|null $description markdown, rendered by the panel's safe renderer
 * @property string|null $banner_color `#rrggbb`
 * @property string|null $banner_image the stored banner file name (ServerPageBanners)
 * @property int $votes
 * @property bool $allow_wake whether visitors may wake the sleeping server (off by default)
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable(['server_id', 'slug', 'published', 'noindex', 'description', 'banner_color', 'banner_image', 'allow_wake'])]
#[RouteKey('slug')]
class ServerPage extends Model
{
    public const string RESOURCE_NAME = 'server_page';

    protected $attributes = [
        'published' => false,
        'noindex' => false,
        'votes' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'published' => 'bool',
            'noindex' => 'bool',
            'allow_wake' => 'bool',
            'votes' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** The public address of the page, from the configured panel address. */
    public function url(): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/s/'.$this->slug;
    }

    /** The public address of the banner image, or null when the page has none. */
    public function bannerUrl(): ?string
    {
        return $this->banner_image === null ? null : '/s/'.$this->slug.'/banner/'.$this->banner_image;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<ServerPageVote, $this>
     */
    public function voteRecords(): HasMany
    {
        return $this->hasMany(ServerPageVote::class);
    }
}
