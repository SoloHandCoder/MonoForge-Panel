<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * Who a tool call runs for: the user who asked, in which assistant ("server" or "admin"),
 * for the server assistant the server they are on, and the conversation the call belongs to
 * (a tool that costs, such as web_search, bills it).
 */
final readonly class ToolContext
{
    public const string SCOPE_SERVER = 'server';

    public const string SCOPE_ADMIN = 'admin';

    public function __construct(
        public User $user,
        public string $scope,
        public ?Server $server = null,
        public ?AiConversation $conversation = null,
    ) {}
}
