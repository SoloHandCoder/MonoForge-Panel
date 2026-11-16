<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Nodes;

use Closure;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Gateway\GatewayHost;
use Pterodactyl\Services\Gateway\GatewayPorts;

class UpdateGatewaySettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminNodesUpdate];
    }

    /**
     * The gateway needs its public host and ports while it is on; turned off, they are kept
     * when given and valid.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $node = $this->route('node');

        return [
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'required_if_accepted:enabled', 'string', 'max:'.GatewayHost::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail) use ($node): void {
                $error = is_string($value) ? GatewayHost::error($value, $node instanceof Node ? $node : null) : null;
                if ($error !== null) {
                    $fail($error);
                }
            }],
            'ports' => ['nullable', 'required_if_accepted:enabled', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $error = is_string($value) ? GatewayPorts::error($value) : null;
                if ($error !== null) {
                    $fail($error);
                }
            }],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'host' => 'Public host',
            'ports' => 'Forwarded ports',
        ];
    }
}
