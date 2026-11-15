<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Http\Requests\Api\Client\Account\GetWalletRequest;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Http\Resources\Api\Client\TokenTransactionResource;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Services\Billing\TokenWallet;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Wallet', 'The token balance and token history of the authenticated user.')]
class WalletController extends ClientApiController
{
    /**
     * The user's balance and token history.
     */
    #[Endpoint('Get wallet', 'Returns the token balance of the authenticated user in meta.balance and their token history, newest first.')]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page. The maximum is 100.', required: false, example: 25)]
    #[ResponseFromResource(TokenTransactionResource::class, TokenTransaction::class, description: 'Wallet returned.', collection: true, resourceKey: 'token_transaction', paginate: 25, meta: ['balance' => 1500])]
    public function __invoke(GetWalletRequest $request, TokenWallet $wallet): ApiResourceCollection
    {
        $user = $request->user();
        $transactions = $user->tokenTransactions()->latest('id')->paginate($request->perPage(25));

        return TokenTransactionResource::collection($transactions)->withMeta(['balance' => $wallet->balance($user)]);
    }
}
