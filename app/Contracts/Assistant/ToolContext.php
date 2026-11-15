<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Assistant;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Who a tool call runs for: the user who asked, in which assistant ("server" or "admin"),
 * and for the server assistant the server they are on.
 */
final readonly class ToolContext
{
    public const string SCOPE_SERVER = 'server';

    public const string SCOPE_ADMIN = 'admin';

    public function __construct(
        public User $user,
        public string $scope,
        public ?Server $server = null,
    ) {}
}
