<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateTebexSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * A blank private key or webhook secret keeps the stored one; an empty address list means
     * Tebex's documented webhook addresses.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'public_token' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9_-]+$/'],
            'private_key' => ['nullable', 'string', 'max:191'],
            'webhook_secret' => ['nullable', 'string', 'max:191'],
            'webhook_ips' => ['present', 'array', 'max:20'],
            'webhook_ips.*' => ['string', 'ip'],
        ];
    }

    /** @return list<string> */
    public function webhookIps(): array
    {
        $ips = [];
        foreach ((array) $this->validated('webhook_ips') as $ip) {
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /** The new value of a write-only secret, or null to keep the stored one. */
    public function secret(string $key): ?string
    {
        $value = mb_trim($this->string($key)->toString());

        return $value === '' ? null : $value;
    }
}
