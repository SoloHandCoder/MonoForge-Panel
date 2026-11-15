<?php

declare(strict_types=1);

namespace Pterodactyl\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Services\Support\SupportAttachmentStore;

/**
 * A support attachment: a PNG, JPEG or WebP image of at most 5 MB, judged by its bytes.
 */
class SupportImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || resolve(SupportAttachmentStore::class)->inspect($value) === null) {
            $fail('Each attachment must be a PNG, JPEG or WebP image of at most 5 MB.');
        }
    }
}
