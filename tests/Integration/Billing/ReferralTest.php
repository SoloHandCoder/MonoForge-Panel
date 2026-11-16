<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Billing\ReferralTest;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\Referral;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\NetworkHash;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\ReferralSettings;
use Pterodactyl\Services\Billing\Tebex\TebexWebhookProcessor;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Notification::fake();
    config()->set('monoforge.captcha.provider', 'none');
    config()->set('monoforge.auth.registration.enabled', true);
    ReferralSettings::store(true, 200, 10);

    $this->pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500, 'bonus_tokens' => 0]);
    $this->referrer = User::factory()->create(['username' => 'sam']);
});

function referralWallet(): TokenWallet
{
    return resolve(TokenWallet::class);
}

/**
 * Signs a visitor up with the referral cookie set, from an address.
 */
function signUpReferred(string $code, string $username = 'alexander', string $ip = '203.0.113.9'): User
{
    Auth::guard('web')->logout();
    Auth::forgetGuards();
    Auth::shouldUse('web');

    // JSON requests carry cookies only with credentials, like the panel's own fetches.
    test()->withCredentials()
        ->withCookie(ReferralSettings::COOKIE, $code)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/auth/register', [
            'username' => $username,
            'email' => $username.'@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => true,
            'terms_version' => 1,
            'privacy_version' => 1,
        ])->assertCreated();

    Auth::guard('web')->logout();
    Auth::forgetGuards();

    return User::query()->where('username', $username)->firstOrFail();
}

/**
 * Sends a Tebex payment event for the user straight to the processor.
 */
function tebex(string $type, string $transactionId, User $user, int $status = 1): void
{
    resolve(TebexWebhookProcessor::class)->handle([
        'id' => (string) Str::uuid(),
        'type' => $type,
        'date' => now()->toAtomString(),
        'subject' => [
            'transaction_id' => $transactionId,
            'status' => ['id' => $status, 'description' => 'Complete'],
            'price_paid' => ['amount' => 4.99, 'currency' => 'EUR'],
            'products' => [[
                'id' => 6012345,
                'name' => 'Pack',
                'quantity' => 1,
                'base_price' => ['amount' => 4.99, 'currency' => 'EUR'],
                'paid_price' => ['amount' => 4.99, 'currency' => 'EUR'],
                'custom' => ['user_id' => $user->id],
            ]],
        ],
    ]);
}

function referralOf(User $user): Referral
{
    return Referral::query()->where('referred_id', $user->id)->firstOrFail();
}

function payDue(): int
{
    return resolve(ReferralService::class)->payoutDue();
}

test('a referral link sets a 30-day cookie for a real code and sends the visitor on to sign up', function (): void {
    $code = resolve(ReferralService::class)->codeFor($this->referrer);

    $response = $this->get('/r/'.mb_strtolower($code))->assertRedirect('/auth/register');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === ReferralSettings::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());

    // An unknown code, or the programme switched off: the same redirect, no cookie.
    $this->get('/r/ZZZZZZZZ')->assertRedirect('/auth/register')->assertCookieMissing(ReferralSettings::COOKIE);
    ReferralSettings::store(false, 200, 10);
    $this->get('/r/'.$code)->assertRedirect('/auth/register')->assertCookieMissing(ReferralSettings::COOKIE);
});

test('the referral code is created once and is case-insensitive', function (): void {
    $service = resolve(ReferralService::class);
    $code = $service->codeFor($this->referrer);

    expect($code)->toMatch('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/')
        ->and($service->codeFor($this->referrer))->toBe($code)
        ->and($service->referrerFor(mb_strtolower($code))?->id)->toBe($this->referrer->id)
        ->and($service->referrerFor('nope'))->toBeNull();
});

test('a sign-up through the link is recorded, the first purchase starts a 7-day hold, and both get the reward after it', function (): void {
    $code = resolve(ReferralService::class)->codeFor($this->referrer);
    $referred = signUpReferred($code);
    $referral = referralOf($referred);

    expect($referral->referrer_id)->toBe($this->referrer->id)
        ->and($referral->status)->toBe(Referral::STATUS_REGISTERED)
        ->and($referral->flagged_at)->toBeNull()
        ->and($referral->network_hash)->toBe(NetworkHash::of('203.0.113.9'));

    tebex('payment.completed', 'tbx-first', $referred);
    $referral->refresh();
    expect($referral->status)->toBe(Referral::STATUS_HELD)
        ->and($referral->tebex_payment_id)->toBe(TebexPayment::query()->where('transaction_id', 'tbx-first')->value('id'))
        ->and($referral->payout_after?->isSameMinute(now()->addDays(7)))->toBeTrue();

    // A second purchase does not move the hold.
    tebex('payment.completed', 'tbx-second', $referred);
    expect($referral->refresh()->tebex_payment_id)->toBe(TebexPayment::query()->where('transaction_id', 'tbx-first')->value('id'));

    // Not before the hold has passed.
    expect(payDue())->toBe(0);
    $this->travel(7)->days();
    $this->travel(1)->minutes();
    expect(payDue())->toBe(1)
        ->and(payDue())->toBe(0);

    $referral->refresh();
    expect($referral->status)->toBe(Referral::STATUS_PAID)
        ->and($referral->reward_tokens)->toBe(200)
        ->and(referralWallet()->balance($this->referrer))->toBe(200)
        ->and(referralWallet()->balance($referred))->toBe(1200)
        ->and(TokenTransaction::query()->where('type', TokenTransaction::TYPE_REFERRAL)->count())->toBe(2)
        ->and(UserNotice::query()->where('user_id', $this->referrer->id)->where('title', 'Referral reward')->exists())->toBeTrue();
});

test("the referred user's first purchase counts only through the referral cookie, and never for the referrer itself", function (): void {
    $code = resolve(ReferralService::class)->codeFor($this->referrer);

    // No cookie: not referred.
    $this->postJson('/auth/register', [
        'username' => 'plainuser', 'email' => 'plain@example.com', 'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery', 'terms' => true, 'terms_version' => 1, 'privacy_version' => 1,
    ])->assertCreated();
    expect(Referral::query()->count())->toBe(0);

    expect(resolve(ReferralService::class)->recordSignup($this->referrer, $code, '192.0.2.1'))->toBeNull()
        ->and(resolve(ReferralService::class)->recordSignup($this->referrer, ['array'], '192.0.2.1'))->toBeNull();
});

test('a refund or dispute within the hold cancels the referral, and nobody is paid', function (string $type): void {
    $referred = signUpReferred(resolve(ReferralService::class)->codeFor($this->referrer));
    tebex('payment.completed', 'tbx-1', $referred);
    tebex($type, 'tbx-1', $referred);

    $referral = referralOf($referred);
    expect($referral->status)->toBe(Referral::STATUS_REVERSED)
        ->and($referral->reversed_at)->not->toBeNull();

    // A later purchase does not qualify it again.
    tebex('payment.completed', 'tbx-2', $referred);
    $this->travel(8)->days();
    expect(payDue())->toBe(0)
        ->and(referralWallet()->balance($this->referrer))->toBe(0)
        ->and(referralOf($referred)->status)->toBe(Referral::STATUS_REVERSED);
})->with(['payment.refunded', 'payment.dispute.lost', 'payment.dispute.opened']);

test('a refund after the payout takes both rewards back and tells the admins', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $referred = signUpReferred(resolve(ReferralService::class)->codeFor($this->referrer));
    tebex('payment.completed', 'tbx-1', $referred);
    $this->travel(8)->days();
    payDue();

    tebex('payment.refunded', 'tbx-1', $referred);
    tebex('payment.dispute.lost', 'tbx-1', $referred);

    $referral = referralOf($referred);
    expect($referral->status)->toBe(Referral::STATUS_REVERSED)
        ->and($referral->flagged_at)->not->toBeNull()
        ->and(referralWallet()->balance($this->referrer))->toBe(0)
        // 500 bought + 200 reward, then the refund (-500) and the reward taken back (-200).
        ->and(referralWallet()->balance($referred))->toBe(0)
        ->and(TokenTransaction::query()->where('type', TokenTransaction::TYPE_REFERRAL)->sum('amount'))->toEqual(0)
        ->and(UserNotice::query()->where('user_id', $admin->id)->where('title', 'Referral reversed after payout')->count())->toBe(1);
});

test("a sign-up from the referrer's network is flagged and waits for an admin; approved it is paid, rejected never", function (): void {
    ActivityLog::query()->forceCreate(['event' => 'auth:success', 'ip' => '198.51.100.20', 'actor_type' => 'user', 'actor_id' => $this->referrer->id, 'properties' => [], 'timestamp' => now()]);
    $code = resolve(ReferralService::class)->codeFor($this->referrer);

    $alt = signUpReferred($code, 'altaccount', '198.51.100.77');
    $referral = referralOf($alt);
    expect($referral->flagged_at)->not->toBeNull()
        ->and($referral->flag_reason)->toBe('Signed up from the same network (/24 or /48) the referrer uses.');

    // A second account from the same (other) network as an earlier referral is flagged too.
    signUpReferred($code, 'friendone', '203.0.113.10');
    $second = signUpReferred($code, 'friendtwo', '203.0.113.11');
    expect(referralOf($second)->flag_reason)->toBe('Another account this user referred signed up from the same network (/24 or /48).');

    tebex('payment.completed', 'tbx-alt', $alt);
    $this->travel(8)->days();
    expect(payDue())->toBe(0)
        ->and(referralOf($alt)->status)->toBe(Referral::STATUS_HELD);

    $admin = User::factory()->create(['root_admin' => true]);
    $this->actingAs($admin)->postJson(route('api.admin.shop.referrals.approve', $referral->id))
        ->assertOk()
        ->assertJsonPath('awaiting_review', false)
        ->assertJsonPath('reviewed_by.id', $admin->id);
    expect(payDue())->toBe(1)
        ->and(referralWallet()->balance($this->referrer))->toBe(200);

    $this->actingAs($admin)->postJson(route('api.admin.shop.referrals.reject', referralOf($second)->id))
        ->assertOk()
        ->assertJsonPath('status', 'rejected');
    tebex('payment.completed', 'tbx-two', $second);
    $this->travel(8)->days();
    payDue();
    expect(referralOf($second)->status)->toBe(Referral::STATUS_REJECTED)
        ->and(referralWallet()->balance($second))->toBe(500);
});

test('the referrer is paid at most the monthly cap; the rest is capped', function (): void {
    ReferralSettings::store(true, 150, 1);
    $code = resolve(ReferralService::class)->codeFor($this->referrer);
    $first = signUpReferred($code, 'firstfriend', '203.0.113.10');
    $second = signUpReferred($code, 'secondfriend', '192.0.2.10');
    tebex('payment.completed', 'tbx-a', $first);
    tebex('payment.completed', 'tbx-b', $second);

    $this->travel(8)->days();
    expect(payDue())->toBe(1)
        ->and(referralOf($first)->status)->toBe(Referral::STATUS_PAID)
        ->and(referralOf($second)->status)->toBe(Referral::STATUS_CAPPED)
        ->and(referralWallet()->balance($this->referrer))->toBe(150)
        ->and(referralWallet()->balance($second))->toBe(500);
});

test('with the programme off nothing is recorded and held rewards wait', function (): void {
    $code = resolve(ReferralService::class)->codeFor($this->referrer);
    $referred = signUpReferred($code);
    tebex('payment.completed', 'tbx-1', $referred);

    ReferralSettings::store(false, 200, 10);
    signUpReferred($code, 'latecomer', '192.0.2.50');
    $this->travel(8)->days();

    expect(Referral::query()->count())->toBe(1)
        ->and(payDue())->toBe(0)
        ->and(referralOf($referred)->status)->toBe(Referral::STATUS_HELD);

    ReferralSettings::store(true, 200, 10);
    expect(payDue())->toBe(1);
});

test('the account page shows the link, the stats and masked referrals', function (): void {
    $this->actingAs(User::factory()->create())->getJson('/api/client/account/referrals')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('reward_tokens', 200)
        ->assertJsonPath('hold_days', 7)
        ->assertJsonPath('stats.signed_up', 0)
        ->assertJsonPath('referrals', []);

    $code = resolve(ReferralService::class)->codeFor($this->referrer);
    $referred = signUpReferred($code);
    tebex('payment.completed', 'tbx-1', $referred);
    $this->travel(8)->days();
    payDue();
    signUpReferred($code, 'another', '192.0.2.50');

    $this->actingAs($this->referrer)->getJson('/api/client/account/referrals')
        ->assertOk()
        ->assertJsonPath('code', $code)
        ->assertJsonPath('path', '/r/'.$code)
        ->assertJsonPath('stats.signed_up', 2)
        ->assertJsonPath('stats.pending', 1)
        ->assertJsonPath('stats.paid', 1)
        ->assertJsonPath('stats.tokens_earned', 200)
        ->assertJsonPath('referrals.0.username', 'an•••')
        ->assertJsonPath('referrals.0.status', 'registered')
        ->assertJsonPath('referrals.1.status', 'paid')
        ->assertJsonPath('referrals.1.reward_tokens', 200)
        ->assertJsonMissing(['email' => 'alexander@example.com']);

    ReferralSettings::store(false, 200, 10);
    $this->actingAs($this->referrer)->getJson('/api/client/account/referrals')
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('code', null);
});

test('admins read and change the settings; approving is root-only from a session', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);

    $this->actingAs($admin)->getJson(route('api.admin.shop.referrals.settings'))
        ->assertOk()
        ->assertExactJson(['enabled' => true, 'reward_tokens' => 200, 'monthly_cap' => 10, 'hold_days' => 7]);
    $this->actingAs($admin)->putJson(route('api.admin.shop.referrals.settings.update'), ['enabled' => true, 'reward_tokens' => 300, 'monthly_cap' => 0])
        ->assertOk()
        ->assertJsonPath('reward_tokens', 300)
        ->assertJsonPath('monthly_cap', 0);
    $this->actingAs($admin)->putJson(route('api.admin.shop.referrals.settings.update'), ['enabled' => true, 'reward_tokens' => 0, 'monthly_cap' => 0])->assertUnprocessable();

    $referred = signUpReferred(resolve(ReferralService::class)->codeFor($this->referrer));
    $referral = referralOf($referred);
    $referral->forceFill(['flagged_at' => now(), 'flag_reason' => 'test'])->save();

    $this->actingAs($admin)->getJson(route('api.admin.shop.referrals', ['flagged' => 1]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.referrer.id', $this->referrer->id)
        ->assertJsonPath('data.0.awaiting_review', true);

    $staff = User::factory()->create(['root_admin' => false]);
    $this->actingAs($staff)->postJson(route('api.admin.shop.referrals.approve', $referral->id))->assertForbidden();

    $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    Auth::forgetGuards();
    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->postJson(route('api.admin.shop.referrals.approve', $referral->id))
        ->assertForbidden();
    expect($referral->refresh()->awaitingReview())->toBeTrue();

    // An admin cannot approve their own referral.
    $own = Referral::query()->forceCreate(['referrer_id' => $admin->id, 'referred_id' => User::factory()->create()->id, 'status' => 'registered', 'flagged_at' => now()]);
    $this->flushHeaders();
    $this->actingAs($admin)->postJson(route('api.admin.shop.referrals.approve', $own->id))->assertStatus(409);
});

test('referral payouts run hourly on one server', function (): void {
    $schedule = resolve(Schedule::class);
    (new Scheduler)($schedule);
    $events = array_values(array_filter($schedule->events(), fn (Event $event): bool => str_contains((string) $event->command, 'p:billing:pay-referrals')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 * * * *')
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->withoutOverlapping)->toBeTrue();
});
