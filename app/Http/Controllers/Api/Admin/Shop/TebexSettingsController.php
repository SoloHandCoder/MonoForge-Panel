<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateTebexSettingsRequest;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;

/**
 * The Tebex Headless store token packs are sold through. The private key and the webhook secret
 * are write-only: stored encrypted and only reported as set or not.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'Configure the token shop: Tebex, token packs and payments.')]
class TebexSettingsController extends AdminApiController
{
    private const array EXAMPLE = [
        'public_token' => 'abcd-1234567890abcdef1234567890abcdef',
        'private_key_set' => true,
        'webhook_secret_set' => true,
        'webhook_ips' => TebexSettings::DEFAULT_WEBHOOK_IPS,
        'default_webhook_ips' => TebexSettings::DEFAULT_WEBHOOK_IPS,
        'webhook_url' => 'https://panel.example.com/api/webhooks/tebex',
        'checkout_enabled' => true,
    ];

    public function __construct(private readonly TebexSettings $settings) {}

    #[Endpoint('Get Tebex settings', 'Returns the Tebex Headless settings and the webhook URL to register in Tebex. Secrets are never returned.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Tebex settings returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse($this->payload());
    }

    #[Endpoint('Update Tebex settings', 'Updates the Tebex Headless settings. A blank private key or webhook secret keeps the stored one.')]
    #[BodyParam('public_token', 'string', 'The Tebex public (webstore) token, or blank to turn the token store off.', required: false, example: 'abcd-1234567890abcdef1234567890abcdef', nullable: true)]
    #[BodyParam('private_key', 'string', 'A new Tebex private key, or blank to keep the stored one.', required: false, example: null, nullable: true)]
    #[BodyParam('webhook_secret', 'string', 'A new Tebex webhook secret, or blank to keep the stored one.', required: false, example: null, nullable: true)]
    #[BodyParam('webhook_ips', 'string[]', 'Addresses Tebex webhooks are accepted from; empty uses the addresses Tebex documents.', required: true, example: ['18.209.80.3', '54.87.231.232'])]
    #[ScribeResponse(self::EXAMPLE, description: 'Tebex settings updated.')]
    public function update(UpdateTebexSettingsRequest $request): JsonResponse
    {
        $privateKey = $request->secret('private_key');
        $webhookSecret = $request->secret('webhook_secret');

        $this->settings->save(
            $request->string('public_token')->toString(),
            $privateKey,
            $webhookSecret,
            $request->webhookIps(),
        );

        Activity::event('admin:tebex.update')
            ->property('private_key_changed', $privateKey !== null)
            ->property('webhook_secret_changed', $webhookSecret !== null)
            ->log();

        return new JsonResponse($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'public_token' => $this->settings->publicToken(),
            'private_key_set' => $this->settings->privateKey() !== '',
            'webhook_secret_set' => $this->settings->webhookSecret() !== '',
            'webhook_ips' => $this->settings->webhookIps(),
            'default_webhook_ips' => TebexSettings::DEFAULT_WEBHOOK_IPS,
            'webhook_url' => $this->settings->webhookUrl(),
            'checkout_enabled' => $this->settings->checkoutEnabled(),
        ];
    }
}
