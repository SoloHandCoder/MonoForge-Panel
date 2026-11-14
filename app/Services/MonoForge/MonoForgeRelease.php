<?php

declare(strict_types=1);

namespace Pterodactyl\Services\MonoForge;

/**
 * A MonoForge release on GitHub. Download URLs are always built from the configured
 * repository and the release tag, never taken from the API response.
 */
final readonly class MonoForgeRelease
{
    public const string TARBALL = 'monoforge-panel.tar.gz';

    public const string CHECKSUM = 'monoforge-panel.tar.gz.sha256';

    public function __construct(
        public string $repo,
        public string $tag,
        public MonoForgeBuild $build,
    ) {}

    /**
     * Read a release from the GitHub "latest release" API response, or null when it is
     * not a MonoForge build release with both the tarball and its checksum attached.
     */
    public static function fromGitHub(string $repo, mixed $data): ?self
    {
        if (! is_array($data) || ($data['draft'] ?? false) === true || ($data['prerelease'] ?? false) === true) {
            return null;
        }

        $tag = $data['tag_name'] ?? null;
        if (! is_string($tag) || preg_match('/^build-([0-9a-f]{7,40})$/i', $tag, $matches) !== 1) {
            return null;
        }

        $assets = [];
        foreach (is_array($data['assets'] ?? null) ? $data['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null)) {
                $assets[] = $asset['name'];
            }
        }

        if (! in_array(self::TARBALL, $assets, true) || ! in_array(self::CHECKSUM, $assets, true)) {
            return null;
        }

        $version = mb_strtolower($matches[1]);
        $target = $data['target_commitish'] ?? null;
        $commit = is_string($target) && preg_match('/^[0-9a-f]{40}$/i', $target) === 1 && str_starts_with(mb_strtolower($target), $version)
            ? mb_strtolower($target)
            : $version;

        return new self($repo, $tag, new MonoForgeBuild(
            $version,
            $commit,
            MonoForgeBuild::parseTime($data['published_at'] ?? $data['created_at'] ?? null),
        ));
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['repo'] ?? null) || ! is_string($data['tag'] ?? null)) {
            return null;
        }

        $build = MonoForgeBuild::fromArray($data['build'] ?? null);

        return $build instanceof MonoForgeBuild ? new self($data['repo'], $data['tag'], $build) : null;
    }

    public function assetUrl(string $name): string
    {
        return sprintf('https://github.com/%s/releases/download/%s/%s', $this->repo, rawurlencode($this->tag), rawurlencode($name));
    }

    public function pageUrl(): string
    {
        return sprintf('https://github.com/%s/releases/tag/%s', $this->repo, rawurlencode($this->tag));
    }

    /**
     * @return array{repo: string, tag: string, build: array{version: string, commit: string, built_at: string|null}}
     */
    public function toArray(): array
    {
        return ['repo' => $this->repo, 'tag' => $this->tag, 'build' => $this->build->toArray()];
    }
}
