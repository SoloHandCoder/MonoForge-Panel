<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client;

/** Any signed-in user may read their own assistant allowance and balance. */
class GetAssistantStatusRequest extends ClientApiRequest {}
