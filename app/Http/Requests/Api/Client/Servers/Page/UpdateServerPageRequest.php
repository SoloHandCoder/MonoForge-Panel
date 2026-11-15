<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Page;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\ServerPageSlug;

class UpdateServerPageRequest extends ServerPageRequest
{
    public const int DESCRIPTION_MAX = 2000;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $server = $this->route()?->parameter('server');
        $page = $server instanceof Server ? ServerPage::query()->where('server_id', $server->id)->first() : null;

        return [
            'slug' => ServerPageSlug::rules($page, $this->user()->root_admin === true, $server instanceof Server ? $server->id : null),
            'published' => ['required', 'boolean'],
            'noindex' => ['required', 'boolean'],
            'allow_wake' => ['sometimes', 'boolean'],
            'description' => ['present', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'banner_color' => ['present', 'nullable', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The address may only use lowercase letters, numbers and single hyphens, and must start and end with a letter or number.',
            'slug.not_in' => 'That address is reserved. Please choose another one.',
            'slug.unique' => 'That address is already taken.',
            'banner_color.regex' => 'The banner colour must be a hex colour such as #8b5cf6.',
        ];
    }
}
