<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * An administrator's change to the help center: an override of a shipped article (a null
 * column keeps the shipped value) or an article of its own. See HelpArticles.
 *
 * @property int $id
 * @property string $slug
 * @property string|null $title
 * @property string|null $category
 * @property string|null $summary
 * @property string|null $content
 * @property int|null $sort_order
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('help_articles')]
#[Fillable(['slug', 'title', 'category', 'summary', 'content', 'sort_order', 'enabled'])]
class HelpArticle extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
