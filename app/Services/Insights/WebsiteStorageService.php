<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Insights;

use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Backup;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * Storage the panel itself uses: its database, its storage folder, and the server backups it records.
 *
 * @phpstan-type WebsiteStorage array{database_bytes: int|null, storage_bytes: int|null, local_backups_bytes: int, local_backups_count: int, remote_backups_bytes: int, remote_backups_count: int}
 */
final readonly class WebsiteStorageService
{
    /** Walking the storage folder is slow on a large panel, so its size is reused for a minute. */
    private const int CACHE_SECONDS = 60;

    /** @return WebsiteStorage */
    public function summary(): array
    {
        $local = $this->backups(Backup::ADAPTER_WINGS);
        $remote = $this->backups(Backup::ADAPTER_AWS_S3);

        return [
            'database_bytes' => $this->databaseBytes(),
            'storage_bytes' => $this->storageBytes(),
            'local_backups_bytes' => $local['bytes'],
            'local_backups_count' => $local['count'],
            'remote_backups_bytes' => $remote['bytes'],
            'remote_backups_count' => $remote['count'],
        ];
    }

    /** The panel database's data and index size, or null where the server does not report it. */
    private function databaseBytes(): ?int
    {
        try {
            $row = DB::selectOne(
                'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                [DB::connection()->getDatabaseName()],
            );
        } catch (Throwable) {
            return null;
        }

        $bytes = is_object($row) ? ($row->bytes ?? null) : null;

        return is_numeric($bytes) ? (int) $bytes : null;
    }

    /** The size of the panel's storage folder (logs, caches, uploads), or null when it cannot be read. */
    private function storageBytes(): ?int
    {
        $bytes = Cache::remember('insights:website:storage', now()->addSeconds(self::CACHE_SECONDS), function (): int {
            try {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(storage_path(), FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY,
                    RecursiveIteratorIterator::CATCH_GET_CHILD,
                );

                $total = 0;
                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    if ($file->isFile() && ! $file->isLink()) {
                        $total += $file->getSize();
                    }
                }

                return $total;
            } catch (UnexpectedValueException) {
                return -1;
            }
        });

        return $bytes >= 0 ? $bytes : null;
    }

    /** @return array{bytes: int, count: int} */
    private function backups(string $disk): array
    {
        $row = Backup::query()->toBase()
            ->where('disk', $disk)
            ->where('is_successful', true)
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(bytes), 0) AS bytes, COUNT(*) AS count')
            ->first();

        $bytes = is_object($row) ? ($row->bytes ?? 0) : 0;
        $count = is_object($row) ? ($row->count ?? 0) : 0;

        return ['bytes' => is_numeric($bytes) ? (int) $bytes : 0, 'count' => is_numeric($count) ? (int) $count : 0];
    }
}
