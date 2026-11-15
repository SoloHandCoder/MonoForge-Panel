<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Requests\Api\Client\Account\AcceptLegalDocumentsRequest;
use Pterodactyl\Services\Legal\LegalSettings;

/**
 * Accepting the current Terms of Service and Privacy Policy. The panel asks signed-in users to
 * do so when either is newer than the version they accepted. That prompt is only in the panel's
 * pages: API keys keep working without it.
 */
#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account', 'View and update the authenticated user account.')]
class LegalAcceptanceController extends ClientApiController
{
    private const array EXAMPLE = [
        'terms_accepted_version' => 2,
        'privacy_accepted_version' => 1,
        'terms_accepted_at' => '2026-11-20T12:00:00+00:00',
    ];

    #[Endpoint('Accept legal documents', 'Records that the authenticated user accepted the current Terms of Service and Privacy Policy. The versions sent must be the current ones.')]
    #[BodyParam('terms_version', 'integer', 'The Terms of Service version the user was shown.', required: true, example: 2)]
    #[BodyParam('privacy_version', 'integer', 'The Privacy Policy version the user was shown.', required: true, example: 1)]
    #[ScribeResponse(self::EXAMPLE, description: 'Acceptance recorded.')]
    public function __invoke(AcceptLegalDocumentsRequest $request): JsonResponse
    {
        $errors = [];
        if ($request->integer('terms_version') !== LegalSettings::version(LegalDocument::Terms)) {
            $errors['terms_version'] = 'The Terms of Service changed while you were reading. Please review the new version.';
        }

        if ($request->integer('privacy_version') !== LegalSettings::version(LegalDocument::Privacy)) {
            $errors['privacy_version'] = 'The Privacy Policy changed while you were reading. Please review the new version.';
        }

        throw_if($errors !== [], ValidationException::withMessages($errors));

        $user = $request->user();
        LegalSettings::recordAcceptance($user);

        return new JsonResponse([
            'terms_accepted_version' => $user->terms_accepted_version,
            'privacy_accepted_version' => $user->privacy_accepted_version,
            'terms_accepted_at' => $user->terms_accepted_at?->toAtomString(),
        ]);
    }
}
