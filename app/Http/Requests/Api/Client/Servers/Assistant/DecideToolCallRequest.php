<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Assistant;

/** Allowing or denying a tool call: the route also requires a panel session, never an API key. */
class DecideToolCallRequest extends AssistantRequest {}
