<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateLegalSettingsRequest;
use Pterodactyl\Services\Legal\LegalSettings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Terms of Service, Privacy Policy and Refund Policy. Each is markdown hosted at
 * /legal/{document}, or an external page the panel links and redirects to.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class LegalController extends AdminApiController
{
    private const array DOCUMENT_EXAMPLE = [
        'document' => 'terms',
        'title' => 'Terms of Service',
        'content' => "# Terms of Service\n\nThese terms apply to everyone who creates an account.",
        'is_default' => false,
        'external_url' => '',
        'public_url' => '/legal/terms',
        'version' => 2,
        'updated_at' => '2026-11-20T12:00:00+00:00',
    ];

    #[Endpoint('Get legal documents', 'Returns the Terms of Service, Privacy Policy and Refund Policy with their markdown, external URL, version and last update.')]
    #[ScribeResponse(['data' => [self::DOCUMENT_EXAMPLE]], description: 'Legal documents returned.')]
    public function index(GetSettingsRequest $request): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($this->document(...), LegalDocument::cases()),
        ]);
    }

    #[Endpoint('Update legal document', 'Stores the markdown and external URL of a legal document. With reaccept the version goes up, so signed-in users accept the Terms of Service or Privacy Policy again.')]
    #[UrlParam('document', 'string', 'The legal document.', example: 'terms', enum: ['terms', 'privacy', 'refunds'])]
    #[BodyParam('content', 'string', 'The markdown of the document. Blank uses the starter text.', required: false, example: "# Terms of Service\n\nThese terms apply to everyone.", nullable: true)]
    #[BodyParam('external_url', 'string', 'An https:// page that holds the document instead. Blank hosts it on the panel.', required: false, example: '', nullable: true)]
    #[BodyParam('reaccept', 'boolean', 'Raise the version so users accept the document again.', required: true, example: true)]
    #[ScribeResponse(self::DOCUMENT_EXAMPLE, description: 'Legal document updated.')]
    public function update(UpdateLegalSettingsRequest $request, string $document): JsonResponse
    {
        $document = LegalDocument::tryFrom($document) ?? throw new NotFoundHttpException;

        LegalSettings::save(
            $document,
            $request->string('content')->toString(),
            $request->string('external_url')->toString(),
            $request->boolean('reaccept'),
        );

        return new JsonResponse($this->document($document));
    }

    /**
     * @return array{document: string, title: string, content: string, is_default: bool, external_url: string, public_url: string, version: int, updated_at: string}
     */
    private function document(LegalDocument $document): array
    {
        return [
            'document' => $document->value,
            'title' => $document->label(),
            'content' => LegalSettings::content($document),
            'is_default' => LegalSettings::isDefault($document),
            'external_url' => LegalSettings::externalUrl($document),
            'public_url' => $document->path(),
            'version' => LegalSettings::version($document),
            'updated_at' => LegalSettings::updatedAt($document)->toAtomString(),
        ];
    }
}
