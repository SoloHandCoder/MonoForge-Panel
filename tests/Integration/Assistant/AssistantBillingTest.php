<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\AssistantBillingTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Assistant\AssistantBilling;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Assistant\FakeAi;

uses(ClientApiIntegrationTestCase::class);

function billing(): AssistantBilling
{
    return resolve(AssistantBilling::class);
}

function userWithTokens(int $tokens): User
{
    $user = User::factory()->create();
    if ($tokens > 0) {
        resolve(TokenWallet::class)->credit($user, $tokens, TokenTransaction::TYPE_PURCHASE, 'Test tokens');
    }

    return $user;
}

test('the free allowance is used first, then the overflow is charged at the ratio', function (): void {
    FakeAi::enable(freeTokens: 1000, ratio: 100);
    $user = userWithTokens(50);

    $first = billing()->record($user, null, AiConversation::SCOPE_SERVER, 600);
    expect([$first->ai_tokens, $first->free_tokens, $first->panel_tokens_charged])->toBe([600, 600, 0]);

    $second = billing()->record($user, null, AiConversation::SCOPE_SERVER, 650);
    expect([$second->ai_tokens, $second->free_tokens, $second->panel_tokens_charged])->toBe([650, 400, 3]);

    expect(resolve(TokenWallet::class)->balance($user))->toBe(47);

    $transaction = TokenTransaction::query()->where('user_id', $user->id)->where('type', TokenTransaction::TYPE_AI_USAGE)->sole();
    expect($transaction->amount)->toBe(-3)
        ->and($transaction->reference_type)->toBe('ai_usage')
        ->and($transaction->reference_id)->toBe((string) $second->id);

    expect(billing()->status($user))->toBe([
        'free_limit' => 1000,
        'free_used' => 1000,
        'ratio' => 100,
        'balance' => 47,
        'warning' => 'free_exhausted',
    ]);
});

test('the overflow is rounded up to whole panel tokens', function (): void {
    FakeAi::enable(freeTokens: 0, ratio: 1000);
    $user = userWithTokens(10);

    expect(billing()->record($user, null, AiConversation::SCOPE_SERVER, 1)->panel_tokens_charged)->toBe(1)
        ->and(billing()->record($user, null, AiConversation::SCOPE_SERVER, 1000)->panel_tokens_charged)->toBe(1)
        ->and(billing()->record($user, null, AiConversation::SCOPE_SERVER, 1001)->panel_tokens_charged)->toBe(2);
});

test('a charge never takes more than the balance', function (): void {
    FakeAi::enable(freeTokens: 0, ratio: 1000);
    $user = userWithTokens(2);

    $usage = billing()->record($user, null, AiConversation::SCOPE_SERVER, 9000);

    expect($usage->panel_tokens_charged)->toBe(2)
        ->and(resolve(TokenWallet::class)->balance($user))->toBe(0)
        ->and(billing()->status($user)['warning'])->toBe('no_balance');
});

test('a turn is refused without free tokens or balance, with a 402 from the API', function (): void {
    FakeAi::enable(freeTokens: 100);
    [$user, $server] = $this->generateTestAccount();
    billing()->record($user, null, AiConversation::SCOPE_SERVER, 100);

    expect(fn () => billing()->assertCanSpend($user, AiConversation::SCOPE_SERVER))->toThrow(AssistantUnavailableException::class);
    // The admin assistant is never refused.
    billing()->assertCanSpend($user, AiConversation::SCOPE_ADMIN);

    Http::fake();
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])
        ->assertStatus(402)
        ->assertJsonPath('errors.0.detail', 'You have used your free AI tokens for this month and your token balance is empty. Top up your wallet to keep using the assistant.');
    Http::assertNothingSent();
    expect(AiConversation::query()->count())->toBe(0);
});

test('a turn stops with a friendly error once free tokens and balance run out mid-turn', function (): void {
    FakeAi::enable(freeTokens: 50);
    FakeAi::tools();
    [$user, $server] = $this->generateTestAccount();
    Http::fakeSequence(FakeAi::URL)->push(FakeAi::toolCall('echo_text', ['text' => 'x'], prompt: 100, completion: 20));

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])
        ->assertOk()
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('error', 'You have used your free AI tokens for this month and your token balance is empty. Top up your wallet to keep using the assistant.')
        ->assertJsonPath('usage.free_used', 50)
        ->assertJsonPath('usage.free_limit', 50);

    Http::assertSentCount(1);
    expect(AiUsage::query()->sole()->free_tokens)->toBe(50);
});

test('the near-limit and exhausted notices are sent once a month', function (): void {
    FakeAi::enable(freeTokens: 1000);
    config()->set('monoforge.ai.warn_percent', 80);
    $user = userWithTokens(100);

    billing()->record($user, null, AiConversation::SCOPE_SERVER, 700);
    expect(UserNotice::query()->count())->toBe(0);

    billing()->record($user, null, AiConversation::SCOPE_SERVER, 150);
    expect(UserNotice::query()->where('kind', AssistantBilling::NOTICE_NEAR)->count())->toBe(1);
    expect(billing()->status($user)['warning'])->toBe('near_limit');

    billing()->record($user, null, AiConversation::SCOPE_SERVER, 10);
    expect(UserNotice::query()->count())->toBe(1);

    billing()->record($user, null, AiConversation::SCOPE_SERVER, 500);
    $exhausted = UserNotice::query()->where('kind', AssistantBilling::NOTICE_EXHAUSTED)->sole();
    expect($exhausted->action_url)->toBe('/account/wallet');

    // Read or not, a second notice of the same kind waits for next month.
    $exhausted->forceFill(['read_at' => CarbonImmutable::now()])->save();
    billing()->record($user, null, AiConversation::SCOPE_SERVER, 500);
    expect(UserNotice::query()->count())->toBe(2);

    // Next month the allowance starts over and so do the notices.
    UserNotice::query()->update(['read_at' => CarbonImmutable::now()]);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth()->addDay());
    billing()->record($user, null, AiConversation::SCOPE_SERVER, 900);
    expect(UserNotice::query()->where('kind', AssistantBilling::NOTICE_NEAR)->count())->toBe(2);
});

test('the admin assistant is recorded but never charged', function (): void {
    FakeAi::enable(freeTokens: 0);
    $user = userWithTokens(100);
    $user->forceFill(['root_admin' => true])->save();

    $usage = billing()->record($user, null, AiConversation::SCOPE_ADMIN, 5000);

    expect([$usage->scope, $usage->ai_tokens, $usage->free_tokens, $usage->panel_tokens_charged])->toBe(['admin', 5000, 0, 0])
        ->and(resolve(TokenWallet::class)->balance($user))->toBe(100);
});

test('every model call of a turn is billed with the tokens it used', function (): void {
    FakeAi::enable(freeTokens: 100000);
    FakeAi::tools();
    [$user, $server] = $this->generateTestAccount();
    Http::fakeSequence(FakeAi::URL)
        ->push(FakeAi::toolCall('echo_text', ['text' => 'x'], prompt: 300, completion: 30))
        ->push(FakeAi::text('done', prompt: 400, completion: 40));

    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/assistant/conversations", ['message' => 'Hi'])
        ->assertOk()
        ->assertJsonPath('usage.free_used', 770);

    expect(AiUsage::query()->orderBy('id')->pluck('ai_tokens')->all())->toBe([330, 440]);
    $this->actingAs($user)->getJson('/api/client/assistant')
        ->assertOk()
        ->assertExactJson(['enabled' => true, 'server_enabled' => true, 'free_limit' => 100000, 'free_used' => 770, 'ratio' => 1000, 'balance' => 0, 'warning' => null]);
});
