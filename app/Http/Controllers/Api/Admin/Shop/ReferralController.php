<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\ReviewReferralRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateReferralSettingsRequest;
use Pterodactyl\Models\Billing\Referral;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\ReferralSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop: the referral programme.')]
class ReferralController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'enabled' => false,
        'reward_tokens' => 200,
        'monthly_cap' => 10,
        'hold_days' => 30,
        'min_purchase_tokens' => 0,
        'effective_min_purchase_tokens' => 500,
    ];

    private const array REFERRAL_EXAMPLE = [
        'id' => 1,
        'referrer' => ['id' => 2, 'username' => 'sam', 'email' => 'sam@example.com'],
        'referred' => ['id' => 9, 'username' => 'alex', 'email' => 'alex@example.com'],
        'status' => 'held',
        'flagged' => true,
        'flag_reason' => 'Signed up from the same network (/24 or /48) the referrer uses.',
        'awaiting_review' => true,
        'reviewed_by' => null,
        'reviewed_at' => null,
        'tebex_transaction_id' => 'tbx-26929122a56954-ef3212',
        'reward_tokens' => null,
        'qualified_at' => '2026-11-16T12:00:00+00:00',
        'payout_after' => '2026-12-16T12:00:00+00:00',
        'paid_at' => null,
        'reversed_at' => null,
        'created_at' => '2026-11-15T12:00:00+00:00',
    ];

    public function __construct(private readonly ReferralService $referrals) {}

    #[Endpoint('Get referral settings', 'Returns whether the referral programme is on, the reward each side gets, the monthly cap per referrer (0 is none), the hold period of the first purchase in days, and the tokens that purchase must credit (min_purchase_tokens as set, where 0 means the smallest visible token pack; effective_min_purchase_tokens is the number in force). A referral only qualifies when the referred user has a linked Discord or Google account that never earned a referral reward before.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Settings returned.')]
    public function settings(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse(ReferralSettings::toArray());
    }

    #[Endpoint('Update referral settings', 'Turns the referral programme on or off and sets the reward, the monthly cap and, optionally, the hold period (1 to 365 days) and the tokens the first purchase must credit (0 is the smallest visible token pack). While it is off no sign-up is recorded as referred and no reward is paid (held rewards wait). Root administrators only.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Settings saved.')]
    public function updateSettings(UpdateReferralSettingsRequest $request): JsonResponse
    {
        ReferralSettings::store(
            $request->boolean('enabled'),
            $request->integer('reward_tokens'),
            $request->integer('monthly_cap'),
            $request->has('hold_days') ? $request->integer('hold_days') : null,
            $request->has('min_purchase_tokens') ? $request->integer('min_purchase_tokens') : null,
        );

        Activity::event('admin:shop.referrals.update')
            ->property([
                'enabled' => $request->boolean('enabled') ? 'yes' : 'no',
                'reward' => $request->integer('reward_tokens'),
                'cap' => $request->integer('monthly_cap'),
                'hold' => ReferralSettings::holdDays(),
            ])
            ->log();

        return new JsonResponse(ReferralSettings::toArray());
    }

    #[Endpoint('List referrals', 'Returns referred sign-ups, newest first. Flagged ones (for example the same network as the referrer) are not paid until approved.')]
    #[QueryParam('status', 'string', 'Only referrals with this status.', required: false, example: 'held', enum: Referral::STATUSES)]
    #[QueryParam('flagged', 'boolean', 'Only flagged referrals.', required: false, example: false)]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[ResponseField('data[].status', 'string', 'registered (no purchase yet), held (first purchase in its hold period), paid, reversed (the purchase was refunded or disputed), rejected or capped (over the monthly cap).', enum: Referral::STATUSES)]
    #[ResponseField('data[].flag_reason', 'string', 'Why it was flagged or reversed.', nullable: true)]
    #[ResponseField('data[].reviewed_by', 'object', 'The administrator who approved or rejected it.', nullable: true)]
    #[ResponseField('data[].reward_tokens', 'integer', 'The tokens each side got once paid.', nullable: true)]
    #[ScribeResponse(['data' => [self::REFERRAL_EXAMPLE], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]], description: 'Referrals returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $referrals = Referral::query()
            ->with(['referrer', 'referred', 'reviewer', 'payment'])
            ->when(in_array($status, Referral::STATUSES, true), fn (Builder $query): Builder => $query->where('status', $status))
            ->when($request->boolean('flagged'), fn (Builder $query): Builder => $query->whereNotNull('flagged_at'))
            ->latest('id')
            ->paginate(50);

        return new JsonResponse([
            'data' => array_map(fn (Referral $referral): array => $referral->toApiArray(), $referrals->items()),
            'meta' => ['current_page' => $referrals->currentPage(), 'last_page' => $referrals->lastPage(), 'total' => $referrals->total()],
        ]);
    }

    #[Endpoint('Approve referral', 'Clears a flagged referral so it is paid once its first purchase has passed the hold period. A referral of your own account cannot be approved. Root administrators only, from a panel session (API keys get 403).')]
    #[UrlParam('referral_id', 'integer', 'The ID of the referral.', example: 1)]
    #[ScribeResponse(self::REFERRAL_EXAMPLE, description: 'Referral approved.')]
    public function approve(ReviewReferralRequest $request, Referral $referral): JsonResponse
    {
        $this->referrals->approve($referral, $request->user());

        Activity::event('admin:referral.approve')->property(['id' => $referral->id, 'referrer' => $referral->referrer_id, 'referred' => $referral->referred_id])->log();

        return new JsonResponse($referral->load(['referrer', 'referred', 'reviewer', 'payment'])->toApiArray());
    }

    #[Endpoint('Reject referral', 'Refuses a referral that has not been paid: it is never paid. Root administrators only, from a panel session (API keys get 403).')]
    #[UrlParam('referral_id', 'integer', 'The ID of the referral.', example: 1)]
    #[ScribeResponse(self::REFERRAL_EXAMPLE, description: 'Referral rejected.')]
    public function reject(ReviewReferralRequest $request, Referral $referral): JsonResponse
    {
        $this->referrals->reject($referral, $request->user());

        Activity::event('admin:referral.reject')->property(['id' => $referral->id, 'referrer' => $referral->referrer_id, 'referred' => $referral->referred_id])->log();

        return new JsonResponse($referral->load(['referrer', 'referred', 'reviewer', 'payment'])->toApiArray());
    }
}
