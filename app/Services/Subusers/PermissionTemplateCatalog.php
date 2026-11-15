<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Subusers;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Servers\SubuserPermissionCatalog;

/**
 * The subuser permission templates a user can pick from: the built-in ones, the global ones an
 * admin made and the user's own. Templates only pre-fill the subuser form; what a subuser may be
 * granted is still checked against the inviter's own permissions when the form is saved.
 *
 * @phpstan-type TemplateRow array{key: string, source: 'builtin'|'global'|'personal', name: string, permissions: list<string>}
 */
final readonly class PermissionTemplateCatalog
{
    /** The built-in templates' keys and names, in the order the picker lists them. */
    public const array BUILT_IN = [
        'viewer' => 'Viewer',
        'moderator' => 'Moderator',
        'builder' => 'Builder',
        'co-owner' => 'Co-owner',
    ];

    public function __construct(private SubuserPermissionCatalog $catalog) {}

    /**
     * Every template the user can pick: built-in, then global, then their own, each by name.
     *
     * @return list<TemplateRow>
     */
    public function forUser(User $user): array
    {
        $global = PermissionTemplate::query()->global()->orderBy('name')->get();
        $personal = $user->permissionTemplates()->orderBy('name')->get();

        return [
            ...$this->builtIn(),
            ...$global->map(fn (PermissionTemplate $template): array => $this->row($template))->values()->all(),
            ...$personal->map(fn (PermissionTemplate $template): array => $this->row($template))->values()->all(),
        ];
    }

    /**
     * The built-in templates, worked out from the permissions the panel and its enabled extensions
     * offer, so a permission an extension adds (such as one for players) is picked up.
     *
     * @return list<TemplateRow>
     */
    public function builtIn(): array
    {
        $keys = $this->catalog->keys();
        $players = array_values(array_filter($keys, fn (string $key): bool => str_contains(mb_strtolower($key), 'player')));

        $sets = [
            'viewer' => [Permissions::FileRead->value, Permissions::FileReadContent->value],
            'moderator' => [
                Permissions::ControlConsole->value,
                Permissions::ControlStart->value,
                Permissions::ControlRestart->value,
                ...$players,
            ],
            'builder' => [
                Permissions::FileRead->value,
                Permissions::FileReadContent->value,
                Permissions::FileCreate->value,
                Permissions::FileUpdate->value,
                Permissions::FileDelete->value,
                Permissions::FileArchive->value,
                Permissions::FileSftp->value,
                Permissions::BackupRead->value,
                Permissions::BackupCreate->value,
                Permissions::BackupDownload->value,
                Permissions::BackupRestore->value,
            ],
            'co-owner' => array_values(array_filter($keys, fn (string $key): bool => ! $this->reservedForOwner($key))),
        ];

        $rows = [];
        foreach (self::BUILT_IN as $key => $name) {
            $rows[] = [
                'key' => "builtin:{$key}",
                'source' => 'builtin',
                'name' => $name,
                'permissions' => $this->clean($sets[$key]),
            ];
        }

        return $rows;
    }

    /**
     * The permissions to store for a template: the known keys, once each, always with console access.
     *
     * @param  array<array-key, mixed>  $permissions
     * @return list<string>
     */
    public function clean(array $permissions): array
    {
        $known = $this->catalog->keys();
        $kept = [Permissions::WebsocketConnect->value];
        foreach ($permissions as $permission) {
            if (is_string($permission) && in_array($permission, $known, true)) {
                $kept[] = $permission;
            }
        }

        return array_values(array_unique($kept));
    }

    /**
     * @return TemplateRow
     */
    public function row(PermissionTemplate $template): array
    {
        $source = $template->user_id === null ? 'global' : 'personal';

        return [
            'key' => "{$source}:{$template->id}",
            'source' => $source,
            'name' => $template->name,
            // A permission that was removed from the panel since the template was saved is left out.
            'permissions' => $this->clean($template->permissions),
        ];
    }

    /**
     * Whether a permission stays with the owner even for a co-owner: managing subusers, billing and
     * deleting the server.
     */
    private function reservedForOwner(string $key): bool
    {
        if (str_starts_with($key, 'user.')) {
            return true;
        }

        $key = mb_strtolower($key);

        return str_contains($key, 'billing') || str_contains($key, 'subscription') || preg_match('/(^|\.)(server\.)?delete-server$|^server\.delete$|^settings\.delete$/', $key) === 1;
    }
}
