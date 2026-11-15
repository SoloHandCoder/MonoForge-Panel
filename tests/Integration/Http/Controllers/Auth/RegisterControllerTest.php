<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Auth\RegisterControllerTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Captcha\HttpCaptchaVerifier;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

/** Accepts exactly one token and records what it was asked. */
final class FakeCaptchaVerifier implements VerifiesCaptcha
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function verify(string $provider, string $token, ?string $ip): array
    {
        $this->calls[] = [$provider, $token];

        return ['success' => $token === 'good-token', 'hostname' => 'localhost'];
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return array_merge([
        'username' => 'newplayer',
        'email' => 'newplayer@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'terms' => true,
    ], $overrides);
}

beforeEach(function (): void {
    Notification::fake();
    config()->set('monoforge.captcha.provider', 'none');
    config()->set('monoforge.auth.registration.enabled', true);
});

test('sign-up does not exist while registration is disabled', function (): void {
    config()->set('monoforge.auth.registration.enabled', false);

    $this->get('/auth/register')->assertNotFound();
    $this->postJson('/auth/register', registration())->assertNotFound();

    expect(User::query()->where('username', 'newplayer')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the sign-up page renders the react shell when registration is enabled', function (): void {
    $this->get('/auth/register')->assertOk()->assertViewIs('templates.auth.core');
});

test('registering creates a normal account and signs it in', function (): void {
    $response = $this->postJson('/auth/register', registration());

    $response->assertCreated()
        ->assertJsonPath('data.complete', true)
        ->assertJsonPath('data.intended', '/')
        ->assertJsonPath('data.user.username', 'newplayer');

    $user = User::query()->where('username', 'newplayer')->firstOrFail();
    expect($user->email)->toBe('newplayer@example.com')
        ->and($user->root_admin)->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

test('sign-up cannot create an administrator', function (): void {
    $this->postJson('/auth/register', registration(['root_admin' => true]))->assertCreated();

    expect(User::query()->where('username', 'newplayer')->firstOrFail()->root_admin)->toBeFalse();
});

test('sign-up validates its fields with the account rules', function (): void {
    $taken = User::factory()->create();

    $this->postJson('/auth/register', registration([
        'username' => $taken->username,
        'email' => 'not-an-email',
        'password_confirmation' => 'something-else',
        'terms' => false,
    ]))->assertUnprocessable()->assertJsonCount(4, 'errors');

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

test('a configured captcha is enforced on sign-up and login', function (): void {
    $verifier = new FakeCaptchaVerifier;
    $this->app->instance(VerifiesCaptcha::class, $verifier);
    config()->set('monoforge.captcha.provider', 'turnstile');

    $this->postJson('/auth/register', registration())->assertBadRequest();
    $this->postJson('/auth/register', registration(['g-recaptcha-response' => 'bad-token']))->assertBadRequest();
    expect(User::query()->where('username', 'newplayer')->exists())->toBeFalse();

    $this->postJson('/auth/register', registration(['cf-turnstile-response' => 'good-token']))->assertCreated();

    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['user' => 'newplayer', 'password' => 'correct-horse-battery'])->assertBadRequest();
    $this->postJson('/auth/login', [
        'user' => 'newplayer',
        'password' => 'correct-horse-battery',
        'g-recaptcha-response' => 'good-token',
    ])->assertOk()->assertJsonPath('data.complete', true);

    expect($verifier->calls)->toBe([
        ['turnstile', 'bad-token'],
        ['turnstile', 'good-token'],
        ['turnstile', 'good-token'],
    ]);
});

test('the turnstile verifier posts the secret and token to cloudflare', function (): void {
    config()->set('monoforge.captcha.turnstile.secret_key', 'turnstile-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'panel.example.com'])]);

    $result = (new HttpCaptchaVerifier)->verify('turnstile', 'token-123', '10.0.0.1');

    expect($result)->toBe(['success' => true, 'hostname' => 'panel.example.com']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['secret'] === 'turnstile-secret'
        && $request['response'] === 'token-123');
});
