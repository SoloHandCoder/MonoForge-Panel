<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Billing\Referral;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\ReferralSettings;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Referrals', 'The referral link of the authenticated user and the rewards it earned.')]
class ReferralController extends ClientApiController
{
    /** The latest referrals shown on the page. */
    private const int LIST_LIMIT = 50;

    private const array EXAMPLE = [
        'enabled' => true,
        'code' => 'K7Q2M9XA',
        'path' => '/r/K7Q2M9XA',
        'reward_tokens' => 200,
        'hold_days' => 7,
        'stats' => ['signed_up' => 3, 'pending' => 1, 'paid' => 1, 'tokens_earned' => 200],
        'referrals' => [[
            'id' => 4,
            'username' => 'al•••',
            'status' => 'held',
            'under_review' => false,
            'reward_tokens' => null,
            'payout_after' => '2026-11-23T12:00:00+00:00',
            'paid_at' => null,
            'created_at' => '2026-11-15T12:00:00+00:00',
        ]],
    ];

    public function __construct(private readonly ReferralService $referrals) {}

    #[Endpoint('Get referrals', "Returns the user's referral link (a path on the panel), the reward, statistics and their latest 50 referred sign-ups. A referred user's first token purchase is rewarded for both once it has stayed paid for the hold period. While the programme is off, enabled is false and there is no code.")]
    #[ResponseField('code', 'string', 'The referral code; null while the programme is off.', nullable: true)]
    #[ResponseField('path', 'string', 'The referral link path on the panel; null while the programme is off.', nullable: true)]
    #[ResponseField('referrals[].username', 'string', 'The start of the referred username, masked.')]
    #[ResponseField('referrals[].status', 'string', 'registered (signed up), held (bought, waiting for the hold period), paid, reversed (the purchase was refunded), rejected or capped (over the monthly limit).', enum: Referral::STATUSES)]
    #[ResponseField('referrals[].under_review', 'boolean', 'Whether an administrator has to look at it before it is paid.')]
    #[ResponseField('referrals[].reward_tokens', 'integer', 'The tokens you got once paid.', nullable: true)]
    #[ResponseField('referrals[].payout_after', 'string', 'When a held reward is paid at the earliest.', nullable: true)]
    #[ResponseField('referrals[].paid_at', 'string', 'When it was paid.', nullable: true)]
    #[ScribeResponse(self::EXAMPLE, description: 'Referrals returned.')]
    public function index(ClientApiRequest $request): JsonResponse
    {
        $user = $request->user();
        $enabled = ReferralSettings::enabled();
        $code = $enabled ? $this->referrals->codeFor($user) : null;

        $referrals = Referral::query()
            ->with('referred')
            ->where('referrer_id', $user->id)
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return new JsonResponse([
            'enabled' => $enabled,
            'code' => $code,
            'path' => $code === null ? null : ReferralService::PATH_PREFIX.$code,
            'reward_tokens' => ReferralSettings::reward(),
            'hold_days' => ReferralSettings::HOLD_DAYS,
            'stats' => $this->referrals->stats($user),
            'referrals' => $referrals->map(fn (Referral $referral): array => [
                'id' => $referral->id,
                'username' => mb_substr($referral->referred->username, 0, 2).'•••',
                'status' => $referral->status,
                'under_review' => $referral->awaitingReview(),
                'reward_tokens' => $referral->status === Referral::STATUS_PAID ? $referral->reward_tokens : null,
                'payout_after' => $referral->payout_after?->toAtomString(),
                'paid_at' => $referral->paid_at?->toAtomString(),
                'created_at' => $referral->created_at?->toAtomString(),
            ])->values()->all(),
        ]);
    }
}
