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
use Pterodactyl\Services\ServerPages\ServerPageTheme;

/**
 * A server's shareable public page at /s/{slug}, set up by its owner or a subuser with
 * `share.manage`, and shown to anyone only while it is published.
 *
 * @property int $id
 * @property int $server_id
 * @property string $slug
 * @property bool $published
 * @property bool $noindex
 * @property bool $discoverable whether the owner lists the page on the discovery page (on by default)
 * @property bool $hidden_from_discovery whether an administrator took the page off the discovery page
 * @property string|null $description markdown, rendered by the panel's safe renderer
 * @property string|null $tagline one plain-text line, at most 80 characters
 * @property string|null $banner_color `#rrggbb`, the page's accent colour
 * @property string $background_style solid, gradient or image ({@see ServerPageTheme})
 * @property string|null $background_from `#rrggbb`
 * @property string|null $background_to `#rrggbb`, the end of a gradient
 * @property string $button_style rounded or square
 * @property bool $show_players
 * @property bool $show_votes
 * @property bool $show_address
 * @property bool $show_description
 * @property string|null $discord_url an https Discord invite
 * @property string|null $website_url an https address
 * @property string|null $banner_image the stored banner file name (ServerPageBanners)
 * @property int $votes
 * @property bool $allow_wake whether visitors may wake the sleeping server (off by default)
 * @property string|null $last_status the status the page last showed, for the discovery page
 * @property int|null $last_players_online
 * @property int|null $last_players_max
 * @property CarbonImmutable|null $status_checked_at when the last status was read
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server|null $server
 */
#[Fillable([
    'server_id', 'slug', 'published', 'noindex', 'discoverable', 'description', 'tagline', 'banner_color', 'banner_image',
    'background_style', 'background_from', 'background_to', 'button_style', 'show_players', 'show_votes', 'show_address',
    'show_description', 'discord_url', 'website_url', 'allow_wake',
])]
#[RouteKey('slug')]
class ServerPage extends Model
{
    public const string RESOURCE_NAME = 'server_page';

    protected $attributes = [
        'published' => false,
        'noindex' => false,
        'discoverable' => true,
        'hidden_from_discovery' => false,
        'background_style' => 'solid',
        'button_style' => 'rounded',
        'show_players' => true,
        'show_votes' => true,
        'show_address' => true,
        'show_description' => true,
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
            'discoverable' => 'bool',
            'hidden_from_discovery' => 'bool',
            'show_players' => 'bool',
            'show_votes' => 'bool',
            'show_address' => 'bool',
            'show_description' => 'bool',
            'allow_wake' => 'bool',
            'votes' => 'int',
            'last_players_online' => 'int',
            'last_players_max' => 'int',
            'status_checked_at' => 'immutable_datetime',
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
