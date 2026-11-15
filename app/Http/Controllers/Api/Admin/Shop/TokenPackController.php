<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\DeleteTokenPackRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreTokenPackRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateTokenPackRequest;
use Pterodactyl\Models\Billing\TokenPack;

/**
 * Token packs map a fixed Tebex package, such as "500 tokens", to the tokens it credits.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'Configure the token shop: Tebex, token packs and payments.')]
class TokenPackController extends AdminApiController
{
    public const array PACK_EXAMPLE = [
        'id' => 1,
        'tebex_package_id' => '6012345',
        'name' => '500 tokens',
        'tokens' => 500,
        'bonus_tokens' => 50,
        'visible' => true,
        'sort' => 0,
    ];

    #[Endpoint('List token packs', 'Returns every token pack in store order.')]
    #[ScribeResponse(['data' => [self::PACK_EXAMPLE]], description: 'Token packs returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse([
            'data' => TokenPack::query()->ordered()->get()->map(fn (TokenPack $pack): array => $pack->toApiArray())->values()->all(),
        ]);
    }

    #[Endpoint('Create token pack', 'Creates a token pack for a Tebex package. Use the numeric package ID from the Tebex creator panel.')]
    #[ScribeResponse(self::PACK_EXAMPLE, status: 201, description: 'Token pack created.')]
    public function store(StoreTokenPackRequest $request): JsonResponse
    {
        $pack = TokenPack::query()->create($request->packAttributes());

        Activity::event('admin:token-pack.create')->property('name', $pack->name)->log();

        return new JsonResponse($pack->toApiArray(), Response::HTTP_CREATED);
    }

    #[Endpoint('Update token pack', 'Updates a token pack. Payments already credited keep their tokens.')]
    #[UrlParam('token_pack', 'integer', 'The ID of the token pack.', example: 1)]
    #[ScribeResponse(self::PACK_EXAMPLE, description: 'Token pack updated.')]
    public function update(UpdateTokenPackRequest $request, TokenPack $tokenPack): JsonResponse
    {
        $tokenPack->fill($request->packAttributes())->save();

        Activity::event('admin:token-pack.update')->property('name', $tokenPack->name)->log();

        return new JsonResponse($tokenPack->toApiArray());
    }

    #[Endpoint('Delete token pack', 'Deletes a token pack. A later payment for its Tebex package credits nothing and is flagged for review.')]
    #[UrlParam('token_pack', 'integer', 'The ID of the token pack.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Token pack deleted.')]
    public function destroy(DeleteTokenPackRequest $request, TokenPack $tokenPack): Response
    {
        $tokenPack->delete();

        Activity::event('admin:token-pack.delete')->property('name', $tokenPack->name)->log();

        return $this->returnNoContent();
    }
}
