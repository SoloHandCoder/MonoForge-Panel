<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Page;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\ServerPageLinks;
use Pterodactyl\Services\ServerPages\ServerPageSlug;
use Pterodactyl\Services\ServerPages\ServerPageText;
use Pterodactyl\Services\ServerPages\ServerPageTheme;

class UpdateServerPageRequest extends ServerPageRequest
{
    public const int DESCRIPTION_MAX = 2000;

    public const string HEX_RULE = 'regex:/\A#[0-9a-fA-F]{6}\z/';

    private ?ServerPage $page = null;

    private bool $pageLoaded = false;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $server = $this->route()?->parameter('server');
        $page = $this->currentPage();

        return [
            'slug' => ServerPageSlug::rules($page, $this->user()->root_admin === true, $server instanceof Server ? $server->id : null),
            'published' => ['required', 'boolean'],
            'noindex' => ['required', 'boolean'],
            'discoverable' => ['sometimes', 'boolean'],
            'allow_wake' => ['sometimes', 'boolean'],
            'description' => ['present', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:'.ServerPageText::TAGLINE_MAX],
            'banner_color' => ['present', 'nullable', 'string', self::HEX_RULE],
            'background_style' => ['sometimes', 'string', Rule::in(ServerPageTheme::BACKGROUND_STYLES)],
            'background_from' => ['sometimes', 'nullable', 'string', self::HEX_RULE],
            'background_to' => ['sometimes', 'nullable', 'string', self::HEX_RULE],
            'button_style' => ['sometimes', 'string', Rule::in(ServerPageTheme::BUTTON_STYLES)],
            'show_players' => ['sometimes', 'boolean'],
            'show_votes' => ['sometimes', 'boolean'],
            'show_address' => ['sometimes', 'boolean'],
            'show_description' => ['sometimes', 'boolean'],
            'discord_url' => ServerPageLinks::rules(true),
            'website_url' => ServerPageLinks::rules(false),
        ];
    }

    /**
     * A gradient needs its second colour, and some text colour must stay readable on both ends.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $page = $this->currentPage();
                $style = $this->has('background_style') ? $this->input('background_style') : ($page->background_style ?? 'solid');
                if ($style !== 'gradient') {
                    return;
                }

                $from = ServerPageTheme::color($this->colorInput('background_from', $page?->background_from)) ?? ServerPageTheme::DEFAULT_BACKGROUND;
                $to = ServerPageTheme::color($this->colorInput('background_to', $page?->background_to));
                if ($to === null) {
                    $validator->errors()->add('background_to', 'Choose the second colour of the gradient.');
                } elseif (ServerPageTheme::textFor($from, $to) === null) {
                    $validator->errors()->add('background_to', 'These two colours are too far apart for text to stay readable on both. Pick colours that are both light or both dark.');
                }
            },
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
            'banner_color.regex' => 'The accent colour must be a hex colour such as #8b5cf6.',
            'background_from.regex' => 'The background colour must be a hex colour such as #241a44.',
            'background_to.regex' => 'The gradient colour must be a hex colour such as #8b5cf6.',
            'tagline.max' => 'The tagline may be at most 80 characters.',
        ];
    }

    private function colorInput(string $key, ?string $current): ?string
    {
        if (! $this->has($key)) {
            return $current;
        }

        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }

    private function currentPage(): ?ServerPage
    {
        if (! $this->pageLoaded) {
            $server = $this->route()?->parameter('server');
            $this->page = $server instanceof Server ? ServerPage::query()->where('server_id', $server->id)->first() : null;
            $this->pageLoaded = true;
        }

        return $this->page;
    }
}
