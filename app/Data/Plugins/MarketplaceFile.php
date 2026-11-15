<?php

declare(strict_types=1);

namespace Pterodactyl\Data\Plugins;

/** A downloadable file of a catalog version, with the hashes the catalog publishes for it. */
final readonly class MarketplaceFile
{
    /** @param  array<string, string>  $hashes  algorithm (sha1, sha256, sha512, md5) => lowercase hex digest */
    public function __construct(
        public string $url,
        public string $filename,
        public int $size,
        public array $hashes,
    ) {}

    /** @return array{filename: string, size: int, hashes: array<string, string>} */
    public function toArray(): array
    {
        return ['filename' => $this->filename, 'size' => $this->size, 'hashes' => $this->hashes];
    }
}
