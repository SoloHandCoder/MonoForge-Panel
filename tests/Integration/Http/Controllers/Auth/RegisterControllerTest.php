<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Auth\RegisterControllerTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Captcha\HttpCaptchaVerifier;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

/**
 * Accepts tokens shaped "good:<action>[@<hostname>]" (hostname defaults to localhost)
 * and records what it was asked.
 */
final class FakeCaptchaVerifier implements VerifiesCaptcha
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function verify(string $provider, string $token, ?string $ip): array
    {
        $this->calls[] = [$provider, $token];
        if (! str_starts_with($token, 'good:')) {
            return ['success' => false, 'hostname' => null, 'action' => null];
        }

        [$action, $hostname] = array_pad(explode('@', mb_substr($token, 5), 2), 2, 'localhost');

        return ['success' => true, 'hostname' => $hostname, 'action' => $action];
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
        'terms_version' => 1,
        'privacy_version' => 1,
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
    // The new user is signed in already, so no "account created" email.
    Notification::assertNothingSent();
});

test('registering records the accepted Terms of Service and Privacy Policy versions', function (): void {
    LegalSettings::save(LegalDocument::Terms, '# Terms', '', reaccept: true);

    $this->postJson('/auth/register', registration(['terms_version' => 2]))->assertCreated();

    $user = User::query()->where('username', 'newplayer')->firstOrFail();
    expect($user->terms_accepted_version)->toBe(2)
        ->and($user->privacy_accepted_version)->toBe(1)
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and(LegalSettings::pendingFor($user))->toBe([]);
});

test('sign-up refuses document versions other than the ones in force', function (): void {
    // The form was opened before the admin published version 2.
    LegalSettings::save(LegalDocument::Privacy, '# Privacy', '', reaccept: true);

    $this->postJson('/auth/register', registration())
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'The Privacy Policy changed while you were reading. Please review the new version.');
    $this->postJson('/auth/register', registration(['terms_version' => null]))->assertUnprocessable();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('sign-up requires accepting the Terms of Service and Privacy Policy', function (): void {
    $this->postJson('/auth/register', registration(['terms' => false]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'You must agree to the Terms of Service and Privacy Policy to create an account.');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('sign-up allows one attempt an hour per email and three per address', function (): void {
    // Forms the validation rejects (other than for the email) do not use attempts.
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/auth/register', registration(['terms' => false]))->assertUnprocessable();
    }

    $this->postJson('/auth/register', registration())->assertCreated();
    $this->postJson('/auth/logout')->assertNoContent();

    // The same email again, in any case, is throttled before the "already taken" check.
    $this->postJson('/auth/register', registration(['username' => 'other', 'email' => ' NewPlayer@Example.com']))
        ->assertTooManyRequests();

    foreach (['second', 'third'] as $name) {
        $this->postJson('/auth/register', registration(['username' => $name, 'email' => "{$name}@example.com"]))->assertCreated();
        $this->postJson('/auth/logout')->assertNoContent();
    }

    $this->postJson('/auth/register', registration(['username' => 'fourth', 'email' => 'fourth@example.com']))
        ->assertTooManyRequests();
    expect(User::query()->count())->toBe(3);
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

    // A token solved on another form or another site is refused.
    $this->postJson('/auth/register', registration(['g-recaptcha-response' => 'good:login']))->assertBadRequest();
    $this->postJson('/auth/register', registration(['g-recaptcha-response' => 'good:register@evil.example']))->assertBadRequest();
    expect(User::query()->where('username', 'newplayer')->exists())->toBeFalse();

    $this->postJson('/auth/register', registration(['cf-turnstile-response' => 'good:register']))->assertCreated();

    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['user' => 'newplayer', 'password' => 'correct-horse-battery'])->assertBadRequest();
    $this->postJson('/auth/login', [
        'user' => 'newplayer',
        'password' => 'correct-horse-battery',
        'g-recaptcha-response' => 'good:register',
    ])->assertBadRequest();
    $this->postJson('/auth/login', [
        'user' => 'newplayer',
        'password' => 'correct-horse-battery',
        'g-recaptcha-response' => 'good:login',
    ])->assertOk()->assertJsonPath('data.complete', true);

    expect($verifier->calls)->toBe([
        ['turnstile', 'bad-token'],
        ['turnstile', 'good:login'],
        ['turnstile', 'good:register@evil.example'],
        ['turnstile', 'good:register'],
        ['turnstile', 'good:register'],
        ['turnstile', 'good:login'],
    ]);
});

test('probing a registered email uses up the attempt for that email', function (): void {
    $taken = User::factory()->create();

    $this->postJson('/auth/register', registration(['email' => $taken->email]))->assertUnprocessable();
    $this->postJson('/auth/register', registration(['email' => $taken->email]))->assertTooManyRequests();
});

test('the turnstile verifier posts the secret and token to cloudflare', function (): void {
    config()->set('monoforge.captcha.turnstile.secret_key', 'turnstile-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'panel.example.com', 'action' => 'register'])]);

    $result = (new HttpCaptchaVerifier)->verify('turnstile', 'token-123', '10.0.0.1');

    expect($result)->toBe(['success' => true, 'hostname' => 'panel.example.com', 'action' => 'register']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
        && $request['secret'] === 'turnstile-secret'
        && $request['response'] === 'token-123');
});
