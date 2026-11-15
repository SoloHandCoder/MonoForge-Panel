<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Laravel\Sanctum\TransientToken;
use Pterodactyl\Contracts\Users\UpdatesUserPasswords;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Account\SetInitialPasswordRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Services\Auth\OAuth\OAuthAccountService;
use Pterodactyl\Services\Auth\OAuth\OAuthClient;
use Pterodactyl\Services\Auth\OAuth\OAuthPending;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Connected accounts', 'Discord and Google accounts linked to the authenticated user for signing in.')]
class OAuthAccountController extends ClientApiController
{
    private const array LIST_EXAMPLE = [
        'has_password' => true,
        'providers' => [[
            'provider' => 'discord',
            'label' => 'Discord',
            'enabled' => true,
            'linked' => true,
            'username' => 'alex',
            'email' => 'alex@example.com',
            'avatar' => 'https://cdn.discordapp.com/avatars/80351110224678912/a_1234.png',
            'linked_at' => '2026-11-15T12:00:00+00:00',
        ]],
    ];

    public function __construct(
        private readonly OAuthAccountService $accounts,
        private readonly OAuthClient $client,
    ) {}

    #[Endpoint('List connected accounts', 'Returns each login provider, whether it is enabled and the linked account of the authenticated user.')]
    #[ScribeResponse(self::LIST_EXAMPLE, description: 'Connected accounts returned.')]
    public function index(ClientApiRequest $request): JsonResponse
    {
        $user = $request->user();
        $linked = UserOAuthAccount::query()->where('user_id', $user->id)->get()->keyBy(fn (UserOAuthAccount $account): string => $account->provider->value);

        return new JsonResponse([
            'has_password' => $this->accounts->hasPassword($user),
            'providers' => array_map(function (OAuthProvider $provider) use ($linked): array {
                $account = $linked->get($provider->value);

                return [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'enabled' => $provider->enabled(),
                    'linked' => $account !== null,
                    'username' => $account?->provider_username,
                    'email' => $account?->provider_email,
                    'avatar' => $account?->provider_avatar,
                    'linked_at' => $account?->created_at?->toAtomString(),
                ];
            }, OAuthProvider::cases()),
        ]);
    }

    #[Endpoint('Start linking an account', 'Returns the provider URL to send the browser to. The provider sends the user back to the panel, which links the account.')]
    #[UrlParam('provider', 'string', 'The login provider.', example: 'discord', enum: ['discord', 'google'])]
    #[ScribeResponse(['url' => 'https://discord.com/oauth2/authorize?client_id=123&state=abc'], description: 'Authorization URL returned.')]
    public function link(ClientApiRequest $request, string $provider): JsonResponse
    {
        $provider = OAuthProvider::tryFrom($provider);
        throw_if(! $provider instanceof OAuthProvider || ! $provider->enabled(), NotFoundHttpException::class);

        // The state lives in the browser session, so API keys cannot start a link.
        $this->requireBrowserSession($request);
        throw_unless($request->hasSession(), DisplayException::class, 'Accounts can only be linked from the panel in your browser.');

        return new JsonResponse([
            'url' => $this->client->begin($provider, OAuthPending::INTENT_LINK, $request->user()->id),
        ]);
    }

    #[Endpoint('Unlink an account', 'Removes the linked account of a provider. Refused when it is the only way left to sign in to the account.')]
    #[UrlParam('provider', 'string', 'The login provider.', example: 'discord', enum: ['discord', 'google'])]
    #[ScribeResponse(status: 204, description: 'Account unlinked.')]
    public function unlink(ClientApiRequest $request, string $provider): JsonResponse
    {
        $this->requireBrowserSession($request);

        $user = $request->user();
        $provider = OAuthProvider::tryFrom($provider) ?? throw new NotFoundHttpException;
        $account = UserOAuthAccount::query()->where('user_id', $user->id)->where('provider', $provider->value)->first()
            ?? throw new NotFoundHttpException;

        throw_if(
            $this->accounts->isLastLogin($user, $account),
            DisplayException::class,
            "Set a password before unlinking {$provider->label()}, or you will not be able to sign in.",
        );

        $account->delete();
        Activity::event('user:account.oauth-unlinked')->subject($user)->property('provider', $provider->value)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    #[Endpoint('Set a password', 'Sets the first password of an account that was created by signing in with Discord or Google.')]
    #[BodyParam('password_confirmation', 'string', 'Confirmation matching the new password.', required: true, example: 'correct-horse-battery-staple')]
    #[ScribeResponse(status: 204, description: 'Password set.')]
    public function setPassword(SetInitialPasswordRequest $request, UpdatesUserPasswords $passwords): JsonResponse
    {
        $this->requireBrowserSession($request);

        $user = $request->user();

        throw_if($this->accounts->hasPassword($user), DisplayException::class, 'Your account already has a password. Change it from the password card instead.');

        $passwords->update($user, $request->string('password')->toString());
        Activity::event('user:account.password-set')->subject($user)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Linking, unlinking and setting the first password change how the account signs in, so
     * they need the signed-in browser session: an API key alone must never be enough to give
     * an account created through Discord or Google a password the key holder knows.
     */
    private function requireBrowserSession(ClientApiRequest $request): void
    {
        throw_unless(
            // Sanctum hands session-authenticated users a TransientToken; API keys are real tokens.
            $request->user()->currentAccessToken() instanceof TransientToken,
            AccessDeniedHttpException::class,
            'This action can only be performed from the panel in your browser, not with an API key.',
        );
    }
}
