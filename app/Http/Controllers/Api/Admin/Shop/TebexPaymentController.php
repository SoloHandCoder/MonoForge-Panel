<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\ApproveTebexPaymentRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Services\Billing\Tebex\TebexWebhookProcessor;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'Configure the token shop: Tebex, token packs and payments.')]
class TebexPaymentController extends AdminApiController
{
    private const array EXAMPLE = [
        'data' => [[
            'id' => 1,
            'transaction_id' => 'tbx-26929122a56954-ef3212',
            'user' => ['id' => 1, 'username' => 'alex', 'email' => 'alex@example.com'],
            'status' => 'refunded',
            'tokens' => 550,
            'tokens_reversed' => 550,
            'amount' => '4.99',
            'currency' => 'EUR',
            'events' => ['payment.completed', 'payment.refunded'],
            'flagged' => true,
            'flag_reason' => null,
            'processed_at' => '2026-11-16T12:00:00+00:00',
            'created_at' => '2026-11-16T12:00:00+00:00',
        ]],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
    ];

    private const array APPROVED_EXAMPLE = [
        'id' => 1,
        'transaction_id' => 'tbx-26929122a56954-ef3212',
        'user' => ['id' => 1, 'username' => 'alex', 'email' => 'alex@example.com'],
        'status' => 'completed',
        'tokens' => 550,
        'tokens_reversed' => 0,
        'amount' => '0.99',
        'currency' => 'EUR',
        'events' => ['payment.completed'],
        'flagged' => true,
        'flag_reason' => '0.99 EUR was paid for 1 × a package priced 4.99 EUR',
        'processed_at' => '2026-11-16T12:05:00+00:00',
        'created_at' => '2026-11-16T12:00:00+00:00',
    ];

    #[Endpoint('List Tebex payments', 'Returns Tebex payments, newest first. Flagged payments (refunds, disputes, payments that matched no user or pack) need a look.')]
    #[QueryParam('flagged', 'boolean', 'Only flagged payments.', required: false, example: false)]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page.', required: false, example: 50)]
    #[ScribeResponse(self::EXAMPLE, description: 'Payments returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $payments = TebexPayment::query()
            ->with('user')
            ->when($request->boolean('flagged'), fn (Builder $query): Builder => $query->whereNotNull('flagged_at'))
            ->latest('id')
            ->paginate($request->perPage());

        return new JsonResponse([
            'data' => array_map(fn (TebexPayment $payment): array => $payment->toApiArray(), $payments->items()),
            'meta' => ['current_page' => $payments->currentPage(), 'last_page' => $payments->lastPage(), 'total' => $payments->total()],
        ]);
    }

    #[Endpoint('Approve and credit a Tebex payment', 'Credits a flagged (underpaid or unmatched) payment through the normal credit path, so a later refund or chargeback takes the tokens back. The tokens go to the user the payment matched; user_id is accepted only for a payment that matched no user, and never the approving admin (422). A payment that does not cover its package price needs accept_underpaid=true (422 otherwise). Approving a payment that is already credited changes nothing. Root administrators only, from a panel session (API keys get 403).')]
    #[UrlParam('payment_id', 'integer', 'The ID of the Tebex payment.', example: 1)]
    #[ScribeResponse(self::APPROVED_EXAMPLE, description: 'Payment credited.')]
    public function approve(ApproveTebexPaymentRequest $request, TebexWebhookProcessor $processor, TebexPayment $payment): JsonResponse
    {
        // The credit and its activity log happen under the payment's lock, once.
        $processor->approve($payment, $request->user(), $request->recipient(), $request->boolean('accept_underpaid'));

        return new JsonResponse($payment->load('user')->toApiArray());
    }
}
