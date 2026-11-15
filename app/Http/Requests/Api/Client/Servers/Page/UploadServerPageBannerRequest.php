<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Page;

use Illuminate\Http\UploadedFile;
use Pterodactyl\Services\ServerPages\ServerPageBanners;
use UnexpectedValueException;

class UploadServerPageBannerRequest extends ServerPageRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        // The type is decided from the bytes by ServerPageBanners, not from the name or client type.
        return [
            'banner' => ['required', 'file', 'max:'.ServerPageBanners::MAX_KILOBYTES],
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('banner');

        return $file instanceof UploadedFile ? $file : throw new UnexpectedValueException('The banner upload is missing.');
    }
}
