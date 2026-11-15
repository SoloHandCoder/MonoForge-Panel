<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Auth\OAuthControllerTest;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Http\Controllers\Auth\OAuthController;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Notification::fake();
    config()->set('app.url', 'https://panel.example.com');
    config()->set('monoforge.auth.registration.enabled', false);
    foreach (['discord', 'google'] as $provider) {
        config()->set("monoforge.auth.{$provider}.enabled", true);
        config()->set("monoforge.auth.{$provider}.client_id", "{$provider}-client");
        config()->set("monoforge.auth.{$provider}.client_secret", "{$provider}-secret");
    }
});

/** @param array<string, mixed> $profile */
function fakeDiscord(array $profile = []): void
{
    Http::fake([
        'discord.com/api/oauth2/token' => Http::response(['access_token' => 'discord-access-token', 'token_type' => 'Bearer']),
        'discord.com/api/users/@me' => Http::response([
            'id' => '80351110224678912',
            'username' => 'nelly',
            'global_name' => 'Nelly',
            'email' => 'nelly@example.com',
            'verified' => true,
            'avatar' => 'abc123',
            ...$profile,
        ]),
    ]);
}

/** Start a sign in with the provider and return the state from the authorize URL. */
function startFlow(string $provider): string
{
    $url = test()->postJson("/auth/modules/{$provider}")->assertOk()->json('url');
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function linkAccount(User $user, string $provider = 'discord', string $id = '80351110224678912'): UserOAuthAccount
{
    return UserOAuthAccount::query()->create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $id]);
}

test('the start endpoint returns the provider url with the registered callback', function (): void {
    $url = $this->postJson('/auth/modules/google')->assertOk()->json('url');

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->toContain('redirect_uri='.rawurlencode('https://panel.example.com/auth/modules/google/authenticate'))
        ->toContain('code_challenge_method=S256')
        ->toContain('scope='.rawurlencode('openid email profile'));
});

test('a disabled provider cannot be started', function (): void {
    config()->set('monoforge.auth.discord.enabled', false);

    $this->postJson('/auth/modules/discord')->assertNotFound();
});

test('a callback whose state does not match is rejected', function (): void {
    fakeDiscord();
    linkAccount(User::factory()->create());
    startFlow('discord');

    $this->get('/auth/modules/discord/authenticate?code=abc&state=forged')
        ->assertRedirect('/auth/login')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.status', 'error');

    $this->assertGuest();
    Http::assertNothingSent();
});

test('a linked account signs its user in', function (): void {
    fakeDiscord();
    $user = User::factory()->create();
    linkAccount($user);

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token'
        && $request['code'] === 'abc'
        && $request['redirect_uri'] === 'https://panel.example.com/auth/modules/discord/authenticate');
    expect(UserOAuthAccount::query()->firstOrFail()->provider_username)->toBe('nelly');
});

test('the state can only be used once', function (): void {
    fakeDiscord();
    linkAccount(User::factory()->create());

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")->assertRedirect('/');
    auth()->logout();

    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")->assertRedirect('/auth/login');
    $this->assertGuest();
});

test('a linked account with two-factor authentication still gets the checkpoint', function (): void {
    fakeDiscord();
    $user = User::factory()->create(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]);
    linkAccount($user);

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")
        ->assertRedirect('/auth/login')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.status', 'checkpoint');

    $this->assertGuest();
    expect(session(OAuthController::RESULT_KEY.'.token'))->toBe(session('auth_confirmation_token')->token);
});

test('an unlinked account is refused while registration is off', function (): void {
    fakeDiscord();
    $existing = User::factory()->create(['email' => 'nelly@example.com']);

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")
        ->assertRedirect('/auth/login')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.message', 'No MonoForge account is linked to this Discord account.');

    // Never linked to the account with the same email.
    $this->assertGuest();
    expect(UserOAuthAccount::query()->where('user_id', $existing->id)->exists())->toBeFalse();
});

test('an unlinked account creates a new user while registration is on', function (): void {
    config()->set('monoforge.auth.registration.enabled', true);
    fakeDiscord();
    User::factory()->create(['username' => 'nelly']);

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")->assertRedirect('/');

    $user = User::query()->where('email', 'nelly@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->username)->not->toBe('nelly')->toStartWith('nelly')
        ->and(User::query()->whereKey($user->id)->value('has_password'))->toBeFalsy()
        ->and(UserOAuthAccount::query()->where('user_id', $user->id)->value('provider_user_id'))->toBe('80351110224678912');
});

test('registration never takes over an existing email or uses an unverified one', function (array $profile, string $message): void {
    config()->set('monoforge.auth.registration.enabled', true);
    fakeDiscord($profile);
    User::factory()->create(['email' => 'nelly@example.com']);

    $state = startFlow('discord');
    $this->get("/auth/modules/discord/authenticate?code=abc&state={$state}")
        ->assertRedirect('/auth/login')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.message', $message);

    $this->assertGuest();
    expect(UserOAuthAccount::query()->count())->toBe(0);
})->with([
    'existing email' => [[], 'A MonoForge account already uses this email address. Sign in with your password, then link Discord from your account page.'],
    'unverified email' => [['verified' => false, 'email' => 'other@example.com'], 'Your Discord account has no verified email address, so a MonoForge account cannot be created from it.'],
]);

test('a signed in user links an account from the account page', function (): void {
    fakeDiscord();
    $user = User::factory()->create();

    $url = $this->actingAs($user)->withHeader('Referer', 'http://localhost')->postJson('/api/client/account/oauth/discord/link')->assertOk()->json('url');
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    $this->get('/auth/modules/discord/authenticate?code=abc&state='.$query['state'])
        ->assertRedirect('/account')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.status', 'success');

    expect(UserOAuthAccount::query()->where('user_id', $user->id)->where('provider', 'discord')->exists())->toBeTrue();
    $this->getJson('/api/client/account/oauth')->assertOk()
        ->assertJsonPath('providers.0.linked', true)
        ->assertJsonPath('providers.0.username', 'nelly')
        ->assertJsonPath('providers.1.linked', false);
});

test('an account linked to someone else cannot be linked again', function (): void {
    fakeDiscord();
    linkAccount(User::factory()->create());
    $user = User::factory()->create();

    $url = $this->actingAs($user)->withHeader('Referer', 'http://localhost')->postJson('/api/client/account/oauth/discord/link')->json('url');
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

    $this->get('/auth/modules/discord/authenticate?code=abc&state='.$query['state'])
        ->assertRedirect('/account')
        ->assertSessionHas(OAuthController::RESULT_KEY.'.message', 'This Discord account is already linked to another MonoForge account.');
    expect(UserOAuthAccount::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('unlinking is refused when it would leave no way to sign in', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['has_password' => false])->save();
    linkAccount($user);

    $this->actingAs($user)->deleteJson('/api/client/account/oauth/discord')->assertBadRequest();
    expect(UserOAuthAccount::query()->count())->toBe(1);

    $this->postJson('/api/client/account/oauth/password', ['password' => 'A-new-password-123', 'password_confirmation' => 'A-new-password-123'])->assertNoContent();
    expect(User::query()->whereKey($user->id)->value('has_password'))->toBeTruthy();

    $this->deleteJson('/api/client/account/oauth/discord')->assertNoContent();
    expect(UserOAuthAccount::query()->count())->toBe(0);
});

test('accounts with a password can unlink and cannot use the first password endpoint', function (): void {
    $user = User::factory()->create();
    linkAccount($user);

    $this->actingAs($user)->postJson('/api/client/account/oauth/password', ['password' => 'A-new-password-123', 'password_confirmation' => 'A-new-password-123'])->assertBadRequest();
    $this->deleteJson('/api/client/account/oauth/discord')->assertNoContent();
    $this->deleteJson('/api/client/account/oauth/google')->assertNotFound();
});
