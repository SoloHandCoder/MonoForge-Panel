<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Store;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Store\CheckoutTokenPackRequest;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Services\Billing\Tebex\TebexClient;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
use Pterodactyl\Services\Legal\LegalSettings;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Store', 'Buy tokens and spend them on servers.')]
class TokenPackController extends ClientApiController
{
    public function __construct(private readonly TebexSettings $settings, private readonly TebexClient $tebex) {}

    #[Endpoint('List token packs', 'Returns the token packs on sale and whether the token store is available.')]
    #[ScribeResponse([
        'enabled' => true,
        'data' => [['id' => 1, 'name' => '500 tokens', 'tokens' => 500, 'bonus_tokens' => 50]],
    ], description: 'Token packs returned.')]
    public function index(ClientApiRequest $request): JsonResponse
    {
        if (! $this->settings->checkoutEnabled()) {
            return new JsonResponse(['enabled' => false, 'data' => []]);
        }

        return new JsonResponse([
            'enabled' => true,
            'data' => TokenPack::query()->where('visible', true)->ordered()->get()->map(fn (TokenPack $pack): array => [
                'id' => $pack->id,
                'name' => $pack->name,
                'tokens' => $pack->tokens,
                'bonus_tokens' => $pack->bonus_tokens,
            ])->values()->all(),
        ]);
    }

    #[Endpoint('Buy token pack', 'Creates a Tebex basket for a token pack and returns the Tebex checkout URL to send the user to. Tokens are credited when Tebex confirms the payment.')]
    #[BodyParam('pack_id', 'integer', 'The token pack to buy.', required: true, example: 1)]
    #[ScribeResponse(['url' => 'https://pay.tebex.io/abcdef-1234567890'], description: 'Checkout started.')]
    #[ScribeResponse(status: 409, description: 'The user has to accept the updated Terms of Service or Privacy Policy first.')]
    public function checkout(CheckoutTokenPackRequest $request): JsonResponse
    {
        LegalSettings::ensureAccepted($request->user());

        throw_unless($this->settings->checkoutEnabled(), DisplayException::class, 'The token store is not available right now.');

        $pack = TokenPack::query()->where('visible', true)->find($request->integer('pack_id'));
        throw_unless($pack instanceof TokenPack, DisplayException::class, 'That token pack is not on sale.');

        $url = $this->tebex->checkout(
            $pack->tebex_package_id,
            ['user_id' => $request->user()->id, 'pack_id' => $pack->id],
            $this->settings->walletUrl('complete'),
            $this->settings->walletUrl('cancelled'),
            $request->ip(),
        );

        return new JsonResponse(['url' => $url]);
    }
}
