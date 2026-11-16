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

        // From the configured address, never the request's Host header, which a client chooses.
        $root = mb_rtrim(config()->string('app.url'), '/');

        return view('templates/base.core', [
            'legalPage' => LegalSettings::pagePayload($document),
            'seo' => [
                'title' => $document->label().' | MonoForge',
                'description' => "The {$document->label()} of MonoForge game server hosting.",
                'url' => $root.$document->path(),
                'image' => $root.'/favicons/android-chrome-512x512.png',
            ],
        ]);
    }
}
