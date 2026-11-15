<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Throwable;

/**
 * Takes IP addresses and node host names out of tool output before it reaches the model
 * or the browser. Every IPv4 and IPv6 literal is replaced, as are the FQDNs of the nodes
 * and the host name aliases of allocations. Values passed as $keep (such as a server's
 * public playit address) are left as they are.
 */
class OutputRedactor
{
    public const string IP = '[hidden-ip]';

    public const string HOST = '[hidden-host]';

    private const string IPV4 = '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?!\d|\.\d)/';

    /** Candidates only: a match is replaced when it is a valid IPv6 address. */
    private const string IPV6 = '/(?<![\w:.])[0-9A-Fa-f]*:[0-9A-Fa-f:.]*[0-9A-Fa-f](?![\w:])/';

    /** @var list<string>|null */
    private ?array $hosts = null;

    /**
     * Whether the text still holds a redaction placeholder, such as a file the model read
     * and is now writing back.
     */
    public static function containsPlaceholder(string $text): bool
    {
        return str_contains($text, self::IP) || str_contains($text, self::HOST) || SecretRedactor::containsPlaceholder($text);
    }

    /**
     * @param  list<string>  $keep
     */
    public function redact(string $text, array $keep = []): string
    {
        if ($text === '') {
            return $text;
        }

        $keep = array_values(array_filter($keep, fn (string $value): bool => $value !== ''));
        $placeholders = [];
        foreach ($keep as $index => $value) {
            $placeholder = "\u{E000}keep{$index}\u{E001}";
            $placeholders[$placeholder] = $value;
            $text = str_replace($value, $placeholder, $text);
        }

        $text = preg_replace_callback(
            self::IPV6,
            fn (array $match): string => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? self::IP : $match[0],
            $text,
        ) ?? $text;

        $text = preg_replace(self::IPV4, self::IP, $text) ?? $text;

        foreach ($this->hosts() as $host) {
            $text = preg_replace('/(?<![\w.-])'.preg_quote($host, '/').'(?![\w-])/i', self::HOST, $text) ?? $text;
        }

        return strtr($text, $placeholders);
    }

    /**
     * Redact every string inside a value, keys included.
     *
     * @param  list<string>  $keep
     */
    public function redactValue(mixed $value, array $keep = []): mixed
    {
        if (is_string($value)) {
            return $this->redact($value, $keep);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[is_string($key) ? $this->redact($key, $keep) : $key] = $this->redactValue($item, $keep);
        }

        return $redacted;
    }

    /**
     * Node FQDNs and allocation aliases that are host names, longest first so a name is
     * never partly replaced by a shorter one it contains.
     *
     * @return list<string>
     */
    private function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        try {
            $names = Node::query()->pluck('fqdn')
                ->merge(Allocation::query()->whereNotNull('ip_alias')->distinct()->pluck('ip_alias'))
                ->filter(fn (mixed $name): bool => is_string($name) && mb_trim($name) !== '' && filter_var(mb_trim($name, '[] '), FILTER_VALIDATE_IP) === false)
                ->map(fn (string $name): string => mb_strtolower(mb_trim($name)))
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            $names = [];
        }

        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this->hosts = $names;
    }
}
