<?php

declare(strict_types=1);

namespace Pterodactyl\Http\ViewComposers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Http\Controllers\Auth\OAuthController;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Auth\OAuth\OAuthIdentity;
use Pterodactyl\Services\Auth\OAuth\OAuthRegistration;
use Pterodactyl\Services\Captcha\CaptchaSettings;
use Pterodactyl\Services\Extensions\ExtensionHeadTags;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Helpers\AssetHashService;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Services\Themes\ThemeResolver;
use Throwable;

class AssetComposer
{
    /**
     * AssetComposer constructor.
     */
    public function __construct(
        private readonly AssetHashService $assetHashService,
        private readonly ExtensionRepository $extensions,
        private readonly ExtensionHeadTags $extensionHeadTags,
        private readonly ThemeResolver $themes,
        private readonly Request $request,
    ) {}

    /**
     * Provide access to the asset service in the views.
     */
    public function compose(View $view): void
    {
        $theme = $this->themes->document($this->request);
        $captcha = CaptchaSettings::provider();

        $view->with('asset', $this->assetHashService);
        // Rendered lazily by the layout; resolving the tags never throws.
        $view->with('extensionHead', $this->extensionHeadTags);
        $view->with('theme', $theme);
        $view->with('siteConfiguration', [
            'name' => config('app.name', 'Pterodactyl'),
            'locale' => config('app.locale', 'en'),
            'recaptcha' => [
                'enabled' => $captcha === CaptchaSettings::RECAPTCHA,
                'siteKey' => config('recaptcha.website_key', ''),
            ],
            // Phase 2 Task G: the enabled Discord/Google logins, and the outcome of the
            // provider callback that redirected here (flashed for this one page load).
            'oauth' => $this->oauthPayload(),
            // The provider the auth forms load (reCAPTCHA or Cloudflare Turnstile) and its public key.
            'captcha' => [
                'provider' => $captcha,
                'siteKey' => CaptchaSettings::siteKey(),
            ],
            'registration' => [
                'enabled' => config()->boolean('monoforge.auth.registration.enabled'),
            ],
            // The largest file the panel serves for editing, so the editor refuses bigger ones up front.
            'files' => [
                'maxEditSize' => config()->integer('pterodactyl.files.max_edit_size'),
            ],
            // The legal documents, and those the signed-in user has to accept again.
            'legal' => $this->legalPayload(),
            // Safe mode loads no extension bundles at all.
            'extensions' => $theme->safeMode ? [] : $this->extensionPayload(array_column($theme->chain, 'id')),
            'theme' => $theme->bootstrap(),
        ]);
    }

    /**
     * @return array{providers: array<string, bool>, result: array{status: string, message?: string, token?: string|null}|null, registration: array{provider: string, label: string, email: string, username: string, nonce: string}|null}
     */
    private function oauthPayload(): array
    {
        $providers = [];
        foreach (OAuthProvider::cases() as $provider) {
            $providers[$provider->value] = $provider->enabled();
        }

        $result = $this->request->hasSession() ? $this->request->session()->get(OAuthController::RESULT_KEY) : null;
        $status = is_array($result) ? ($result['status'] ?? null) : null;
        $pending = $this->request->hasSession() && ! Auth::check() ? OAuthRegistration::peek($this->request->session()) : null;

        return [
            'providers' => $providers,
            'result' => is_string($status) ? [
                'status' => $status,
                'message' => is_string($result['message'] ?? null) ? $result['message'] : '',
                'token' => is_string($result['token'] ?? null) ? $result['token'] : null,
            ] : null,
            // A Discord/Google sign-up waiting for the visitor to accept the terms.
            'registration' => $pending instanceof OAuthIdentity ? [
                'provider' => $pending->provider->value,
                'label' => $pending->provider->label(),
                'email' => (string) $pending->email,
                'username' => (string) ($pending->displayName ?? $pending->username),
                // Posted back by the consent screen, so it creates exactly this sign-up.
                'nonce' => (string) OAuthRegistration::nonce($this->request->session()),
            ] : null,
        ];
    }

    /**
     * @return array{documents: array<string, array{title: string, url: string, external: bool, version: int, updatedAt: string}>, pending: list<string>}
     */
    private function legalPayload(): array
    {
        $user = Auth::user();

        return LegalSettings::sitePayload($user instanceof User ? $user : null);
    }

    /**
     * The enabled frontend extensions the SPA boot loader should import, with their
     * frontend settings for signed-in users and only the public ones for guests, and
     * the themes of the chain the page renders with. Never allowed to break page
     * rendering - worst case the SPA sees no extensions.
     *
     * @param  list<string>  $themes
     * @return array<int, array{id: string, version?: string, entry: string}>
     */
    private function extensionPayload(array $themes): array
    {
        if (! config('extensions.enabled')) {
            return [];
        }

        try {
            return $this->extensions->frontendPayload(authenticated: Auth::check(), themes: $themes);
        } catch (Throwable) {
            return [];
        }
    }
}
