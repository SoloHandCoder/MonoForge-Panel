<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Providers\SettingsServiceProvider;

class UpdateMailSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * Validation rules for updating mail settings.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'driver' => ['required', 'string', Rule::in(SettingsServiceProvider::MAIL_DRIVERS)],
            'smtp' => ['exclude_unless:driver,smtp', 'required', 'array'],
            'smtp.host' => ['exclude_unless:driver,smtp', 'required', 'string'],
            'smtp.port' => ['exclude_unless:driver,smtp', 'required', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['exclude_unless:driver,smtp', 'present', Rule::in([null, 'tls', 'ssl'])],
            'smtp.username' => ['exclude_unless:driver,smtp', 'nullable', 'string', 'max:191'],
            'smtp.password' => ['exclude_unless:driver,smtp', 'nullable', 'string', 'max:191'],
            'smtp.from_address' => ['exclude_unless:driver,smtp', 'nullable', 'string', 'email'],
            'smtp.from_name' => ['exclude_unless:driver,smtp', 'nullable', 'string', 'max:191'],
            'from_address' => ['required', 'string', 'email'],
            'from_name' => ['nullable', 'string', 'max:191'],
            // Write-only: blank keeps the stored key, "!e" clears it.
            'resend' => ['sometimes', 'array'],
            'resend.api_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Map the input onto settings storage keys. Secrets (the SMTP password and the
     * Resend API key) are only included when present so they are never blanked out,
     * and SMTP keys are only written while SMTP is the selected driver.
     *
     * @param  list<string>|null  $only
     * @return array<string, string|int|null>
     */
    public function normalize(?array $only = null): array
    {
        $driver = $this->string('driver')->toString();
        $values = ['mail:default' => $driver];

        if ($driver === 'smtp') {
            $values['mail:mailers:smtp:host'] = $this->string('smtp.host')->toString();
            $values['mail:mailers:smtp:port'] = $this->integer('smtp.port');
            $values['mail:mailers:smtp:encryption'] = $this->filled('smtp.encryption') ? $this->string('smtp.encryption')->toString() : null;
            $values['mail:mailers:smtp:username'] = $this->filled('smtp.username') ? $this->string('smtp.username')->toString() : null;

            if (! empty($this->input('smtp.password'))) {
                $values['mail:mailers:smtp:password'] = $this->string('smtp.password')->toString();
            }
        }

        $values['mail:from:address'] = $this->string('from_address')->toString();
        $values['mail:from:name'] = $this->filled('from_name') ? $this->string('from_name')->toString() : null;

        if (! empty($this->input('resend.api_key'))) {
            $values['services:resend:key'] = $this->string('resend.api_key')->toString();
        }

        return $values;
    }

    /**
     * Older clients send only the smtp block with the sender inside it: they mean
     * the SMTP driver, and their smtp.from_* fields are the sender.
     */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['driver' => 'smtp']);

        if (! $this->has('from_address') && $this->has('smtp.from_address')) {
            $this->merge(['from_address' => $this->input('smtp.from_address')]);
        }

        if (! $this->has('from_name') && $this->has('smtp.from_name')) {
            $this->merge(['from_name' => $this->input('smtp.from_name')]);
        }
    }
}
