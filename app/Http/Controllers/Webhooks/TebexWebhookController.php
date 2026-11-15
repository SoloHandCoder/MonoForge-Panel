<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
use Pterodactyl\Services\Billing\Tebex\TebexWebhookProcessor;

/**
 * Receives Tebex webhooks. It sits outside the session and API middleware, so it has no CSRF
 * check and no user: a request is trusted only when it comes from an allowed address and its
 * `X-Signature` is the HMAC-SHA256 of the SHA256 of the raw body, keyed with the webhook secret.
 *
 * @see https://docs.tebex.io/developers/webhooks/overview
 */
class TebexWebhookController extends Controller
{
    public function __construct(private readonly TebexSettings $settings, private readonly TebexWebhookProcessor $processor) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Tebex suggests a 404 for requests from anywhere else.
        if (! in_array($request->ip(), $this->settings->webhookIps(), true)) {
            return new JsonResponse(['error' => 'Not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $secret = $this->settings->webhookSecret();
        if ($secret === '') {
            Log::warning('A Tebex webhook arrived, but no webhook secret is set in the panel.');

            return new JsonResponse(['error' => 'Not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $body = $request->getContent();
        $signature = mb_strtolower(mb_trim((string) $request->header('X-Signature', '')));
        $expected = hash_hmac('sha256', hash('sha256', $body), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            Log::warning('A Tebex webhook was rejected: its signature does not match.');

            return new JsonResponse(['error' => 'Invalid signature.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return new JsonResponse(['error' => 'Malformed webhook.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Tebex validates an endpoint by expecting its webhook ID back.
        if ($event['type'] !== 'validation.webhook') {
            $this->processor->handle($event);
        }

        return new JsonResponse(['id' => $event['id']]);
    }
}
