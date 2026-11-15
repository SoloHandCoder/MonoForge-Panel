<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Users\AdjustUserTokensRequest;
use Pterodactyl\Http\Requests\Api\Admin\Users\GetUserTokensRequest;
use Pterodactyl\Http\Resources\Api\Admin\TokenTransactionResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Users', 'Create, update, retrieve, and delete panel users.')]
class TokenController extends AdminApiController
{
    /**
     * List user token transactions.
     */
    #[Endpoint('List user token transactions', "Returns the user's token balance in meta.balance and their token ledger, newest first.")]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page. The maximum is 100.', required: false, example: 25)]
    #[ResponseFromResource(TokenTransactionResource::class, TokenTransaction::class, description: 'Token ledger returned.', collection: true, resourceKey: 'token_transaction', paginate: 25, meta: ['balance' => 1500])]
    public function index(GetUserTokensRequest $request, TokenWallet $wallet, User $user): ApiResourceCollection
    {
        $transactions = $user->tokenTransactions()->latest('id')->paginate($request->perPage(25));

        return TokenTransactionResource::collection($transactions)->withMeta(['balance' => $wallet->balance($user)]);
    }

    /**
     * Adjust user tokens.
     */
    #[Endpoint('Adjust user tokens', "Adds (positive amount) or removes (negative amount) tokens from a user's balance with a reason, recorded as an admin_adjust ledger row. Removing more than the balance fails unless allow_negative is set.")]
    #[ResponseFromResource(TokenTransactionResource::class, TokenTransaction::class, status: 201, description: 'Tokens adjusted.', resourceKey: 'token_transaction', meta: ['balance' => 1500])]
    public function store(AdjustUserTokensRequest $request, TokenWallet $wallet, User $user): JsonResponse
    {
        $amount = $request->amount();
        $reason = $request->reason();
        $meta = ['admin_id' => $request->user()->id];

        $transaction = $amount > 0
            ? $wallet->credit($user, $amount, TokenTransaction::TYPE_ADMIN_ADJUST, $reason, 'admin', (string) $request->user()->id, $meta)
            : $wallet->debit($user, -$amount, TokenTransaction::TYPE_ADMIN_ADJUST, $reason, 'admin', (string) $request->user()->id, $meta, $request->boolean('allow_negative'));

        Activity::event('admin:user.adjust-tokens')
            ->subject($user)
            ->property(['email' => $user->email, 'amount' => $amount, 'balance' => $transaction->balance_after, 'reason' => $reason])
            ->log();

        return TokenTransactionResource::make($transaction)
            ->withMeta(['balance' => $transaction->balance_after])
            ->respond(Response::HTTP_CREATED);
    }
}
