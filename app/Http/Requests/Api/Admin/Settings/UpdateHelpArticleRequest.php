<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Closure;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Help\HelpArticles;

class UpdateHelpArticleRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * A shipped article takes its starter value for a blank field; an added article needs a
     * title and some markdown of its own.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $article = $this->route('article');
        $shipped = is_string($article) && isset(HelpArticles::defaults()[$article]);
        $required = $shipped ? 'nullable' : 'required';

        return [
            'title' => [$required, 'string', 'max:'.HelpArticles::MAX_TITLE],
            'category' => ['nullable', 'string', 'max:'.HelpArticles::MAX_CATEGORY],
            'summary' => ['nullable', 'string', 'max:'.HelpArticles::MAX_SUMMARY],
            'content' => [
                $required,
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && mb_strlen($value, '8bit') > HelpArticles::MAX_CONTENT_BYTES) {
                        $fail('The article may be at most '.number_format(HelpArticles::MAX_CONTENT_BYTES).' bytes long.');
                    }
                },
            ],
            'order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'title' => 'Title',
            'category' => 'Category',
            'summary' => 'Summary',
            'content' => 'Content',
            'order' => 'Order',
            'enabled' => 'Shown',
        ];
    }
}
