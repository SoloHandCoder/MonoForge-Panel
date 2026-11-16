<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Nodes;

use Closure;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Gateway\GatewayPorts;

class CheckGatewayRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminNodesRead];
    }

    /**
     * Ports to check instead of the stored ones, such as those typed in but not saved yet.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'ports' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $error = is_string($value) ? GatewayPorts::error($value) : null;
                if ($error !== null) {
                    $fail($error);
                }
            }],
        ];
    }
}
