<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use finfo;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportMessage;
use RuntimeException;
use Throwable;

/**
 * Checks, stores and reads the images on support messages. A file is accepted only when its
 * bytes are a PNG, JPEG or WebP image (magic bytes, libmagic and the image header all agree),
 * whatever its name or the type the browser sent. Every image is decoded and encoded again
 * with GD, which drops EXIF and any other metadata, and is scaled down to MAX_STORED_SIDE.
 * Files are kept on the private disk under a random name and are never served from a public path.
 */
class SupportAttachmentStore
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_PER_MESSAGE = 3;

    /** The widest or tallest image accepted. */
    public const int MAX_DIMENSION = 10000;

    /** The most pixels an upload may have; checked from the header, before anything is decoded. */
    public const int MAX_PIXELS = 8_000_000;

    /** Stored images are scaled down so neither side is longer than this. */
    public const int MAX_STORED_SIDE = 2560;

    public const string DIRECTORY = 'support';

    public const string INVALID = 'Each attachment must be a PNG, JPEG or WebP image of at most 5 MB.';

    public const string TOO_MANY_PIXELS = 'Each attachment may have at most 8 megapixels. Scale the image down and try again.';

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
        $bytes = $this->read($file);

        return $bytes === null ? null : $this->inspectBytes($bytes);
    }

    /** Why the upload is refused, or null when it is an accepted image. */
    public function problem(UploadedFile $file): ?string
    {
        $info = $this->inspect($file);
        if ($info === null) {
            return self::INVALID;
        }

        return $info['width'] * $info['height'] > self::MAX_PIXELS ? self::TOO_MANY_PIXELS : null;
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
     * Checks an upload and produces the bytes to store: re-encoded without metadata and scaled
     * down when GD is available. Nothing is written yet.
     *
     * @return array{bytes: string, name: string, mime: string, extension: string, width: int, height: int}
     *
     * @throws ValidationException when the file is not an accepted image or cannot be processed
     */
    public function prepare(UploadedFile $file): array
    {
        $bytes = $this->read($file);
        $info = $bytes === null ? null : $this->inspectBytes($bytes);
        if ($bytes === null || $info === null) {
            $this->reject(self::INVALID);
        }

        // The header gave the size; nothing larger than this is ever handed to the decoder.
        if ($info['width'] * $info['height'] > self::MAX_PIXELS) {
            $this->reject(self::TOO_MANY_PIXELS);
        }

        $prepared = $this->canReencode() ? $this->reencode($info['mime'], $bytes) : $this->keepWithoutGd($info, $bytes);

        return [
            ...$prepared,
            'name' => self::displayName($file->getClientOriginalName(), $info['extension']),
            'mime' => $info['mime'],
            'extension' => $info['extension'],
        ];
    }

    /**
     * Writes a prepared image to the private disk and records it on the message.
     *
     * @param  array{bytes: string, name: string, mime: string, extension: string, width: int, height: int}  $prepared
     */
    public function put(SupportMessage $message, array $prepared): SupportAttachment
    {
        $path = sprintf('%s/%d/%s.%s', self::DIRECTORY, $message->ticket_id, Str::random(40), $prepared['extension']);
        throw_unless($this->disk()->put($path, $prepared['bytes']), RuntimeException::class, 'The attachment could not be stored.');

        try {
            return $message->attachments()->create([
                'path' => $path,
                'name' => $prepared['name'],
                'mime' => $prepared['mime'],
                'size' => mb_strlen($prepared['bytes'], '8bit'),
                'width' => $prepared['width'],
                'height' => $prepared['height'],
            ]);
        } catch (Throwable $throwable) {
            $this->disk()->delete($path);

            throw $throwable;
        }
    }

    /** Checks, re-encodes and stores an uploaded image for a message. */
    public function store(SupportMessage $message, UploadedFile $file): SupportAttachment
    {
        return $this->put($message, $this->prepare($file));
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

    /** Whether GD can decode and encode images here. */
    protected function canReencode(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    private function read(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return null;
        }

        $size = $file->getSize();
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Without GD nothing can strip metadata or scale: the file is kept as sent, except that a
     * JPEG carrying EXIF (camera, time, often GPS) is refused.
     *
     * @param  array{mime: string, extension: string, width: int, height: int}  $info
     * @return array{bytes: string, width: int, height: int}
     */
    private function keepWithoutGd(array $info, string $bytes): array
    {
        if ($info['mime'] === 'image/jpeg' && $this->hasExif($bytes)) {
            $this->reject('This photo carries camera data (EXIF) that cannot be removed here. Export it without metadata and try again.');
        }

        return ['bytes' => $bytes, 'width' => $info['width'], 'height' => $info['height']];
    }

    /** Whether a JPEG carries an EXIF block. */
    private function hasExif(string $bytes): bool
    {
        return preg_match('/\xFF\xE1..Exif\x00\x00/s', mb_substr($bytes, 0, 65_536, '8bit')) === 1;
    }

    /**
     * Decodes the image and encodes only its pixels again, turned upright for a photo's EXIF
     * orientation (the tag itself is dropped) and scaled so neither side is over MAX_STORED_SIDE.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function reencode(string $mime, string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            $this->reject('The image is corrupt or unreadable.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->upright($image, $this->jpegOrientation($bytes));
        }

        $image = $this->scaleDown($image);
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

        $width = imagesx($image);
        $height = imagesy($image);

        if (! $written || $encoded === '') {
            $this->reject('The image could not be processed.');
        }

        return ['bytes' => $encoded, 'width' => $width, 'height' => $height];
    }

    private function scaleDown(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::MAX_STORED_SIDE) {
            return $image;
        }

        $ratio = self::MAX_STORED_SIDE / $longest;
        $newWidth = max(1, min(self::MAX_STORED_SIDE, (int) round($width * $ratio)));
        $newHeight = max(1, min(self::MAX_STORED_SIDE, (int) round($height * $ratio)));

        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        if (! $scaled instanceof GdImage) {
            $this->reject('The image could not be processed.');
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($scaled, 0, 0, $transparent);
        }

        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $scaled;
    }

    /** Applies an EXIF orientation (2-8), since the re-encoded file no longer carries the tag. */
    private function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** The orientation tag of a JPEG's EXIF block (1 when there is none), read without the exif extension. */
    private function jpegOrientation(string $bytes): int
    {
        $length = mb_strlen($bytes, '8bit');
        $position = 2;

        while ($position + 4 <= $length && ord($bytes[$position]) === 0xFF) {
            $marker = ord($bytes[$position + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $segment = $this->uint($bytes, $position + 2, 2, true);
            if ($segment === null || $segment < 2) {
                break;
            }

            if ($marker === 0xE1 && mb_substr($bytes, $position + 4, 6, '8bit') === "Exif\0\0") {
                return $this->tiffOrientation($bytes, $position + 10, min($length, $position + 2 + $segment));
            }

            $position += 2 + $segment;
        }

        return 1;
    }

    private function tiffOrientation(string $bytes, int $tiff, int $end): int
    {
        $order = mb_substr($bytes, $tiff, 2, '8bit');
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $big = $order === 'MM';
        $ifd = $this->uint($bytes, $tiff + 4, 4, $big);
        $count = $ifd === null ? null : $this->uint($bytes, $tiff + $ifd, 2, $big);
        if ($ifd === null || $count === null) {
            return 1;
        }

        for ($i = 0; $i < min($count, 64); $i++) {
            $entry = $tiff + $ifd + 2 + ($i * 12);
            if ($entry + 12 > $end) {
                break;
            }

            if ($this->uint($bytes, $entry, 2, $big) === 0x0112) {
                $value = $this->uint($bytes, $entry + 8, 2, $big) ?? 1;

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /** An unsigned 2- or 4-byte integer at the offset, or null when the bytes run out. */
    private function uint(string $bytes, int $offset, int $size, bool $bigEndian): ?int
    {
        if ($offset < 0 || $offset + $size > mb_strlen($bytes, '8bit')) {
            return null;
        }

        $format = match ($size) {
            2 => $bigEndian ? 'n' : 'v',
            default => $bigEndian ? 'N' : 'V',
        };
        $value = unpack($format, $bytes, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : null;
    }

    /**
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['attachments' => $message]);
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
