<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Entrance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;

/**
 * The `<name>` in a server's shared Minecraft entrance address `<name>.<domain>`: one DNS
 * label, unique across the panel, never one of the names a domain usually needs for itself.
 * A server gets one the first time it is routed (its public page slug when it has a page,
 * otherwise its name) and keeps it; the owner can change it on the server's network page.
 */
final class EntranceHostnames
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 40;

    /** Names the domain may need for something else, or that look official. */
    public const array RESERVED = [
        'www', 'play', 'admin', 'administrator', 'api', 'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'mx',
        'ns', 'ns1', 'ns2', 'dns', 'ftp', 'sftp', 'ssh', 'panel', 'node', 'wings', 'status', 'help', 'support',
        'docs', 'cdn', 'static', 'assets', 'webmail', 'login', 'auth', 'root', 'staff', 'mod', 'moderator',
        'owner', 'official', 'monoforge', 'gateway', 'proxy', 'localhost', 'test', 'dev', 'staging',
    ];

    /** A DNS label: lowercase letters, digits and inner hyphens. */
    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D';

    /**
     * Why the name cannot be used, or null when it can. `$for` is the server asking, which may
     * keep its own name.
     */
    public static function error(string $name, ?Server $for = null): ?string
    {
        $length = mb_strlen($name);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return sprintf('The name must be %d to %d characters long.', self::MIN_LENGTH, self::MAX_LENGTH);
        }

        if (preg_match(self::LABEL, $name) !== 1 || str_contains($name, '--')) {
            return 'Use lowercase letters, digits and single hyphens, starting and ending with a letter or digit.';
        }

        if (in_array($name, self::RESERVED, true)) {
            return 'This name is reserved. Pick another one.';
        }

        $taken = Server::query()->where('mc_hostname', $name)
            ->when($for instanceof Server, fn (Builder $query): Builder => $query->where('id', '!=', $for?->id))
            ->exists();

        return $taken ? 'Another server already uses this name.' : null;
    }

    /** The server's name, given one now when it has none yet. */
    public static function for(Server $server): string
    {
        if (is_string($server->mc_hostname) && $server->mc_hostname !== '') {
            return $server->mc_hostname;
        }

        $name = self::generate($server);
        // Quietly: a name is not a change the server's listeners need to see.
        $server->forceFill(['mc_hostname' => $name])->saveQuietly();

        return $name;
    }

    /** A free name for the server: its public page slug, else its name, made unique if needed. */
    public static function generate(Server $server): string
    {
        $slug = ServerPage::query()->where('server_id', $server->id)->value('slug');
        $base = self::label(is_string($slug) && $slug !== '' ? $slug : $server->name);
        $short = mb_strtolower(mb_substr((string) $server->uuidShort, 0, 8));

        $candidates = $base === null ? [] : [$base, mb_substr($base, 0, self::MAX_LENGTH - 5).'-'.mb_substr($short, 0, 4)];
        $candidates[] = 'mc-'.$short;

        foreach ($candidates as $candidate) {
            if (self::error($candidate, $server) === null) {
                return $candidate;
            }
        }

        return 'mc-'.$short.'-'.mb_strtolower(Str::random(4));
    }

    /** Text as a DNS label, or null when too little of it is left. */
    public static function label(string $text): ?string
    {
        $label = mb_trim(mb_substr(Str::slug($text), 0, 32), '-');
        $label = (string) preg_replace('/-+/', '-', $label);

        return mb_strlen($label) >= self::MIN_LENGTH && ! in_array($label, self::RESERVED, true) ? $label : null;
    }
}
