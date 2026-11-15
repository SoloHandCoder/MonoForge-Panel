<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Banner images of the public server pages. Only PNG, JPEG and WebP are taken, decided from the
 * bytes (never the client's name or type), at most 1 MB and 4096 px a side. When GD is installed
 * the image is decoded and encoded again, which drops metadata and anything hidden after the
 * pixels; without it the image must still parse as what its signature says. Files get a random
 * name on the private disk and are served only through /s/{slug}/banner/{file}.
 */
class ServerPageBanners
{
    public const int MAX_KILOBYTES = 1024;

    public const int MAX_DIMENSION = 4096;

    /** The detected type mapped to the stored file extension. No SVG, GIF or anything else. */
    public const array TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public const string NAME_PATTERN = '[a-f0-9]{40}\.(?:png|jpg|webp)';

    private const string DISK = 'local';

    private const string DIRECTORY = 'server-pages';

    /** Stores the upload under a new random name and returns that name. */
    public function store(UploadedFile $upload): string
    {
        $bytes = $upload->isValid() ? $upload->getContent() : '';
        if ($bytes === '') {
            $this->reject('The image is empty or could not be read.');
        }

        if (mb_strlen($bytes, '8bit') > self::MAX_KILOBYTES * 1024) {
            $this->reject(sprintf('The image is larger than %d KB.', self::MAX_KILOBYTES));
        }

        $mime = $this->detect($bytes) ?? $this->reject('Only PNG, JPEG and WebP images can be used.');
        $this->assertDimensions($mime, $bytes);
        $bytes = $this->reencode($mime, $bytes);

        $disk = $this->disk();
        do {
            $name = bin2hex(random_bytes(20)).'.'.self::TYPES[$mime];
        } while ($disk->exists($this->path($name)));

        if (! $disk->put($this->path($name), $bytes)) {
            $this->reject('The image could not be saved.');
        }

        return $name;
    }

    /**
     * The bytes and type of a stored banner, or null when the name is not one this class makes.
     *
     * @return array{contents: string, mime: string}|null
     */
    public function read(string $name): ?array
    {
        if (preg_match('/\A'.self::NAME_PATTERN.'\z/', $name) !== 1) {
            return null;
        }

        $contents = $this->disk()->get($this->path($name));
        $mime = array_search(pathinfo($name, PATHINFO_EXTENSION), self::TYPES, true);

        return $contents === null || $mime === false ? null : ['contents' => $contents, 'mime' => $mime];
    }

    public function delete(?string $name): void
    {
        if ($name !== null && preg_match('/\A'.self::NAME_PATTERN.'\z/', $name) === 1) {
            $this->disk()->delete($this->path($name));
        }
    }

    /** The type of the bytes from their signature alone. */
    private function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && mb_substr($bytes, 8, 4, '8bit') === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** The header has to agree with the signature and give sane dimensions, before anything is decoded. */
    private function assertDimensions(string $mime, string $bytes): void
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size['mime'] !== $mime) {
            $this->reject('The image is corrupt or unreadable.');
        }

        if ($size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
            $this->reject(sprintf('The image must be at most %d pixels on each side.', self::MAX_DIMENSION));
        }
    }

    /** Decodes and encodes the image again when GD can, so only its pixels are kept. */
    private function reencode(string $mime, string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            $this->reject('The image is corrupt or unreadable.');
        }

        imagesavealpha($image, true);
        ob_start();
        try {
            $written = match ($mime) {
                'image/png' => imagepng($image, null, 6),
                'image/jpeg' => imagejpeg($image, null, 85),
                default => imagewebp($image, null, 85),
            };
        } finally {
            $encoded = (string) ob_get_clean();
        }

        if (! $written || $encoded === '') {
            $this->reject('The image could not be processed.');
        }

        return $encoded;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function path(string $name): string
    {
        return self::DIRECTORY.'/'.$name;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['banner' => $message]);
    }
}
