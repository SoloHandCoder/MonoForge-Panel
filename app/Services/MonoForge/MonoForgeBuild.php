<?php

declare(strict_types=1);

namespace Pterodactyl\Services\MonoForge;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One MonoForge build: the short commit it was built from, the full commit and when it was built.
 */
final readonly class MonoForgeBuild
{
    public function __construct(
        public string $version,
        public string $commit,
        public ?CarbonImmutable $builtAt = null,
    ) {}

    /**
     * Read a build from version.json contents, or null when they are not a valid build.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;
        $commit = $data['commit'] ?? null;
        if (! is_string($version) || ! is_string($commit) || ! self::isCommitish($version) || ! self::isCommitish($commit)) {
            return null;
        }

        return new self(mb_strtolower($version), mb_strtolower($commit), self::parseTime($data['built_at'] ?? null));
    }

    public static function isCommitish(string $value): bool
    {
        return preg_match('/^[0-9a-f]{7,40}$/i', $value) === 1;
    }

    public static function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether $latest should replace $current: an unknown current build, or a different
     * commit that was not built before the current one.
     */
    public static function isNewer(?self $current, self $latest): bool
    {
        if (! $current instanceof self) {
            return true;
        }

        if ($current->sameCommitAs($latest)) {
            return false;
        }

        if ($current->builtAt instanceof CarbonImmutable && $latest->builtAt instanceof CarbonImmutable) {
            return $latest->builtAt->greaterThan($current->builtAt);
        }

        return true;
    }

    /**
     * Whether both builds come from the same commit. Short and full hashes of one commit match.
     */
    public function sameCommitAs(self $other): bool
    {
        $mine = mb_strlen($this->commit) >= mb_strlen($other->commit) ? $this->commit : $other->commit;
        $theirs = $mine === $this->commit ? $other->commit : $this->commit;

        return str_starts_with($mine, $theirs);
    }

    /**
     * @return array{version: string, commit: string, built_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'commit' => $this->commit,
            'built_at' => $this->builtAt?->toIso8601ZuluString(),
        ];
    }
}
