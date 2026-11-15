<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportMessage;
use RuntimeException;
use Throwable;

/**
 * Checks, stores and reads the images on support messages. A file is accepted only when its
 * bytes are a PNG, JPEG or WebP image (magic bytes, libmagic and the image header all agree),
 * whatever its name or the type the browser sent. Files are kept on the private disk under a
 * random name and are never served from a public path.
 */
class SupportAttachmentStore
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_PER_MESSAGE = 3;

    /** The widest or tallest image accepted, which also keeps a decompression bomb out. */
    public const int MAX_DIMENSION = 10000;

    public const string DIRECTORY = 'support';

    /** @var array<string, array{type: int, extension: string}> */
    private const array TYPES = [
        'image/png' => ['type' => IMAGETYPE_PNG, 'extension' => 'png'],
        'image/jpeg' => ['type' => IMAGETYPE_JPEG, 'extension' => 'jpg'],
        'image/webp' => ['type' => IMAGETYPE_WEBP, 'extension' => 'webp'],
    ];

    /**
     * A name to show and download the file under: the upload's base name in safe characters,
     * with the extension of what the file really is.
     */
    public static function displayName(string $original, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._ -]+/', '', $base) ?? '';
        $base = mb_trim(mb_substr($base, 0, 80), ' .-');

        return ($base === '' ? 'image' : $base).'.'.$extension;
    }

    /**
     * What the file really is, or null when it is not an accepted image.
     *
     * @return array{mime: string, extension: string, width: int, height: int}|null
     */
    public function inspect(UploadedFile $file): ?array
    {
        if (! $file->isValid()) {
            return null;
        }

        $size = $file->getSize();
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) ? $this->inspectBytes($bytes) : null;
    }

    /**
     * @return array{mime: string, extension: string, width: int, height: int}|null
     */
    public function inspectBytes(string $bytes): ?array
    {
        if ($bytes === '' || mb_strlen($bytes, '8bit') > self::MAX_BYTES) {
            return null;
        }

        $mime = $this->magicType($bytes);
        if ($mime === null) {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($detected !== $mime) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || $info[2] !== self::TYPES[$mime]['type']) {
            return null;
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return null;
        }

        return ['mime' => $mime, 'extension' => self::TYPES[$mime]['extension'], 'width' => $width, 'height' => $height];
    }

    /**
     * Stores an uploaded image for a message. The caller validated it; a file that no longer
     * passes the check is refused here too.
     */
    public function store(SupportMessage $message, UploadedFile $file): SupportAttachment
    {
        $bytes = @file_get_contents($file->getRealPath());
        $info = is_string($bytes) ? $this->inspectBytes($bytes) : null;
        throw_if(! is_string($bytes) || $info === null, RuntimeException::class, 'The attachment is not a PNG, JPEG or WebP image.');

        $path = sprintf('%s/%d/%s.%s', self::DIRECTORY, $message->ticket_id, Str::random(40), $info['extension']);
        throw_unless($this->disk()->put($path, $bytes), RuntimeException::class, 'The attachment could not be stored.');

        try {
            return $message->attachments()->create([
                'path' => $path,
                'name' => self::displayName($file->getClientOriginalName(), $info['extension']),
                'mime' => $info['mime'],
                'size' => mb_strlen($bytes, '8bit'),
                'width' => $info['width'],
                'height' => $info['height'],
            ]);
        } catch (Throwable $throwable) {
            $this->disk()->delete($path);

            throw $throwable;
        }
    }

    /** The stored bytes, or null when the file is gone. */
    public function contents(SupportAttachment $attachment): ?string
    {
        $contents = $this->disk()->get($attachment->path);

        return is_string($contents) ? $contents : null;
    }

    /**
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        if ($paths !== []) {
            $this->disk()->delete($paths);
        }
    }

    private function magicType(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            mb_substr($bytes, 0, 4, '8bit') === 'RIFF' && mb_substr($bytes, 8, 4, '8bit') === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }
}
