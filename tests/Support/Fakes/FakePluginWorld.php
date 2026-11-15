<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Fakes;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * An in-memory server filesystem behind fake Wings file endpoints, plus fake catalog APIs and
 * download hosts, all through Http::fake. Wings "pulls" fetch from $remote by URL, so a test
 * controls exactly what each allowed (or disallowed) host serves.
 */
final class FakePluginWorld
{
    /** @var array<string, string> path => contents */
    public array $files = [];

    /** @var array<string, true> */
    public array $directories = ['' => true];

    /** @var array<string, string> URL => body served to Wings pulls */
    public array $remote = [];

    /** @var array<string, array<mixed>|Closure(Request): (array<mixed>|PromiseInterface)> URL pattern => JSON */
    public array $catalog = [];

    /** @var list<array{method: string, url: string, data: array<mixed>}> */
    public array $requests = [];

    /** @var list<string> */
    public array $pulled = [];

    public function __construct()
    {
        Http::fake(fn (Request $request): ?PromiseInterface => $this->handle($request));
    }

    /** @param  array<string, string>  $entries  path in the zip => contents */
    public static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mrpack');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $data = (string) file_get_contents($path);
        @unlink($path);

        return $data;
    }

    public function file(string $path, string $contents): self
    {
        $path = $this->clean($path);
        $this->ensureParents($path);
        $this->files[$path] = $contents;

        return $this;
    }

    public function directory(string $path): self
    {
        $path = $this->clean($path);
        $this->ensureParents($path.'/x');

        return $this;
    }

    /** @return list<array{method: string, url: string, data: array<mixed>}> */
    public function requestsTo(string $pattern): array
    {
        return array_values(array_filter($this->requests, fn (array $request): bool => Str::is($pattern, $request['url'])));
    }

    private function handle(Request $request): ?PromiseInterface
    {
        $data = $request->isJson() ? ($request->data() ?: []) : [];
        $this->requests[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $data];

        if (preg_match('#/api/servers/[^/]+/(.+?)(\?|$)#', $request->url(), $match) === 1) {
            return $this->wings($request, $match[1], $data);
        }

        foreach ($this->catalog as $pattern => $response) {
            if (Str::is($pattern, $request->url())) {
                $body = $response instanceof Closure ? $response($request) : $response;

                return $body instanceof PromiseInterface ? $body : Http::response($body);
            }
        }

        return null;
    }

    /** @param  array<mixed>  $data */
    private function wings(Request $request, string $action, array $data): PromiseInterface
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        switch ($action) {
            case 'files/list-directory':
                $directory = $this->clean((string) ($query['directory'] ?? '/'));
                if (! isset($this->directories[$directory])) {
                    return Http::response(['error' => 'not found'], 404);
                }

                return Http::response($this->listing($directory));
            case 'files/contents':
                $file = $this->clean((string) ($query['file'] ?? ''));

                return isset($this->files[$file])
                    ? Http::response($this->files[$file], 200, ['Content-Length' => (string) mb_strlen($this->files[$file])])
                    : Http::response(['error' => 'not found'], 404);
            case 'files/pull':
                $url = (string) $data['url'];
                if (! isset($this->remote[$url])) {
                    return Http::response(['error' => 'remote failed'], 400);
                }

                $this->pulled[] = $url;
                $target = $this->join((string) $data['root'], (string) ($data['file_name'] ?? basename($url)));
                $this->ensureParents($target);
                $this->files[$target] = $this->remote[$url];

                return Http::response([]);
            case 'files/delete':
                foreach ((array) $data['files'] as $file) {
                    $this->delete($this->join((string) $data['root'], (string) $file));
                }

                return Http::response('', 204);
            case 'files/rename':
                foreach ((array) $data['files'] as $rename) {
                    $from = $this->join((string) $data['root'], (string) $rename['from']);
                    $to = $this->join((string) $data['root'], (string) $rename['to']);
                    $parent = Str::contains($to, '/') ? Str::beforeLast($to, '/') : '';
                    if (isset($this->files[$to]) || isset($this->directories[$to]) || ! isset($this->directories[$parent])) {
                        return Http::response(['error' => 'cannot rename to '.$to], 400);
                    }

                    if (isset($this->files[$from])) {
                        $this->files[$to] = $this->files[$from];
                        unset($this->files[$from]);
                    } else {
                        return Http::response(['error' => 'missing '.$from], 404);
                    }
                }

                return Http::response('', 204);
            case 'files/create-directory':
                $parent = $this->clean((string) $data['path']);
                if (! isset($this->directories[$parent])) {
                    return Http::response(['error' => 'no parent'], 400);
                }

                $this->directories[$this->join($parent, (string) $data['name'])] = true;

                return Http::response('', 204);
            case 'files/decompress':
                $root = $this->clean((string) $data['root']);
                $archive = $this->join($root, (string) $data['file']);
                $path = tempnam(sys_get_temp_dir(), 'unzip');
                file_put_contents($path, $this->files[$archive] ?? '');
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) {
                    return Http::response(['error' => 'not an archive'], 400);
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (str_ends_with($name, '/')) {
                        continue;
                    }

                    $target = $this->join($root, $name);
                    $this->ensureParents($target);
                    $this->files[$target] = (string) $zip->getFromIndex($i);
                }

                $zip->close();
                @unlink($path);

                return Http::response('', 204);
            case 'backup':
                return Http::response('', 204);
        }

        return Http::response(['error' => 'unexpected '.$action], 500);
    }

    /** @return list<array<string, mixed>> */
    private function listing(string $directory): array
    {
        $entries = [];
        $prefix = $directory === '' ? '' : $directory.'/';
        foreach (array_keys($this->directories) as $path) {
            if ($path !== '' && $path !== $directory && str_starts_with($path, $prefix) && ! str_contains(mb_substr($path, mb_strlen($prefix)), '/')) {
                $entries[] = $this->entry(mb_substr($path, mb_strlen($prefix)), false, 0);
            }
        }

        foreach ($this->files as $path => $contents) {
            if (str_starts_with($path, $prefix) && ! str_contains(mb_substr($path, mb_strlen($prefix)), '/')) {
                $entries[] = $this->entry(mb_substr($path, mb_strlen($prefix)), true, mb_strlen($contents));
            }
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private function entry(string $name, bool $file, int $size): array
    {
        return ['name' => $name, 'mode' => '-rw-r--r--', 'mode_bits' => '0644', 'size' => $size, 'file' => $file, 'symlink' => false, 'mime' => $file ? 'application/java-archive' : 'inode/directory', 'created' => '2026-11-01T00:00:00Z', 'modified' => '2026-11-01T00:00:00Z'];
    }

    private function delete(string $path): void
    {
        unset($this->files[$path], $this->directories[$path]);
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $path.'/')) {
                unset($this->files[$file]);
            }
        }

        foreach (array_keys($this->directories) as $directory) {
            if (str_starts_with($directory, $path.'/')) {
                unset($this->directories[$directory]);
            }
        }
    }

    private function ensureParents(string $path): void
    {
        $parts = explode('/', $path);
        array_pop($parts);
        $current = '';
        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current.'/'.$part;
            $this->directories[$current] = true;
        }
    }

    private function join(string $root, string $name): string
    {
        return $this->clean($this->clean($root).'/'.$name);
    }

    private function clean(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                // Wings keeps everything inside the server root.
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
