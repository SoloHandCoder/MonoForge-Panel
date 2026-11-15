<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Models\Model;

/**
 * An OpenAI-compatible chat completions API the assistant can use. The API key is encrypted
 * at rest and never leaves the panel: API responses only say whether one is set.
 *
 * @property int $id
 * @property string $name
 * @property AiProviderPreset $preset
 * @property string $base_url
 * @property string|null $api_key
 * @property string $model
 * @property array<array-key, mixed>|null $extra_headers Header names and values; validated as strings on input.
 * @property int $context_window
 * @property bool $supports_tools
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'preset',
    'base_url',
    'api_key',
    'model',
    'extra_headers',
    'context_window',
    'supports_tools',
    'enabled',
])]
#[Hidden(['api_key'])]
#[RouteKey('id')]
#[Table(name: 'ai_providers')]
class AiProvider extends Model
{
    public const string RESOURCE_NAME = 'ai_provider';

    protected $attributes = [
        'context_window' => 32000,
        'supports_tools' => true,
        'enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preset' => AiProviderPreset::class,
            'api_key' => 'encrypted',
            'extra_headers' => 'array',
            'context_window' => 'int',
            'supports_tools' => 'bool',
            'enabled' => 'bool',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function hasKey(): bool
    {
        return is_string($this->api_key) && $this->api_key !== '';
    }

    /**
     * The admin view of the provider; the key itself is never included.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'preset' => $this->preset->value,
            'base_url' => $this->base_url,
            'has_key' => $this->hasKey(),
            'model' => $this->model,
            'extra_headers' => $this->extra_headers ?? (object) [],
            'context_window' => $this->context_window,
            'supports_tools' => $this->supports_tools,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
