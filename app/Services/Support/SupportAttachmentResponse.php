<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Illuminate\Http\Response;
use Pterodactyl\Models\Support\SupportAttachment;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves a stored support image. The type is the one the panel detected when it stored the
 * file, never sniffed again by the browser, and the response runs sandboxed with no scripts,
 * so even a crafted image cannot act on the panel's origin.
 */
final class SupportAttachmentResponse
{
    public static function make(SupportAttachment $attachment): Response
    {
        $contents = resolve(SupportAttachmentStore::class)->contents($attachment);
        throw_if($contents === null, NotFoundHttpException::class, 'The attachment file is missing.');

        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment->name) ?? 'image';

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $attachment->mime,
            'Content-Length' => (string) mb_strlen($contents, '8bit'),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $attachment->name, $fallback),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
