<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Billing\TebexCheckout;
use Pterodactyl\Services\Billing\TokenWallet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Wallet', 'The token balance and token history of the authenticated user.')]
class TokenPurchaseController extends ClientApiController
{
    public const string EXAMPLE_UUID = '0b8f6c2e-5d4a-4e7b-9c1f-3a2d6e8b7f10';

    /**
     * The state of one of the user's own token pack checkouts, as Tebex's webhooks reported it.
     */
    #[Endpoint('Get token purchase', 'Returns the state of a token pack checkout the authenticated user started, as confirmed by Tebex: pending until a payment webhook arrives, then completed, under review, refunded or declined. Checkouts of other users return 404.')]
    #[UrlParam('checkout', 'string', 'The checkout reference returned when the checkout was started.', example: self::EXAMPLE_UUID)]
    #[ScribeResponse([
        'data' => [
            'id' => self::EXAMPLE_UUID,
            'status' => 'completed',
            'pack_name' => '500 tokens',
            'tokens' => 550,
            'balance' => 1550,
            'created_at' => '2026-11-15T12:00:00+00:00',
        ],
    ], description: 'Token purchase returned.')]
    #[ScribeResponse(status: 404, description: 'No checkout of the authenticated user has this reference.')]
    #[ResponseField('data', 'object', 'The token purchase.')]
    #[ResponseField('data.id', 'string', 'The checkout reference.')]
    #[ResponseField('data.status', 'string', 'The payment state confirmed by Tebex.', enum: TebexCheckout::STATUSES)]
    #[ResponseField('data.pack_name', 'string', 'The name of the token pack bought, or null when the pack was deleted.', nullable: true)]
    #[ResponseField('data.tokens', 'integer', 'The tokens credited for the payment; 0 until it is completed.')]
    #[ResponseField('data.balance', 'integer', "The user's token balance now.")]
    #[ResponseField('data.created_at', 'string', 'When the checkout was started, in ISO 8601 format.')]
    public function __invoke(ClientApiRequest $request, TokenWallet $wallet, string $checkout): JsonResponse
    {
        $user = $request->user();

        // Only the user's own checkout: anything else is indistinguishable from a missing one.
        $purchase = TebexCheckout::query()
            ->with(['pack', 'payment'])
            ->where('uuid', $checkout)
            ->where('user_id', $user->id)
            ->first();
        throw_unless($purchase instanceof TebexCheckout, NotFoundHttpException::class);

        $status = $purchase->status();

        return new JsonResponse([
            'data' => [
                'id' => $purchase->uuid,
                'status' => $status,
                'pack_name' => $purchase->pack?->name,
                'tokens' => $status === TebexCheckout::STATUS_COMPLETED ? (int) $purchase->payment?->tokens : 0,
                'balance' => $wallet->balance($user),
                'created_at' => $purchase->created_at?->toAtomString(),
            ],
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
