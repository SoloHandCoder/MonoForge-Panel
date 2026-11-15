<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Legal\LegalSettings;

/**
 * The public /legal/{document} pages. Readable without signing in; a document that lives on an
 * external page redirects there, any other one is rendered by the SPA from the page bootstrap.
 */
class LegalPageController extends Controller
{
    public function __invoke(string $document): View|RedirectResponse
    {
        $document = LegalDocument::from($document);

        $external = LegalSettings::externalUrl($document);
        if ($external !== '') {
            return new RedirectResponse($external);
        }

        return view('templates/base.core', ['legalPage' => LegalSettings::pagePayload($document)]);
    }
}
