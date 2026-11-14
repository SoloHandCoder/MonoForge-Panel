<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

/**
 * Everything the operator needs to see after (or instead of) an import: what
 * moved, what did not, and what was left behind on purpose.
 */
final class ImportSummary
{
    /** @var array<string, int> target table => rows written */
    public array $copied = [];

    /** @var array<string, string> table => why it was skipped */
    public array $skipped = [];

    /** @var string[] */
    public array $notes = [];

    /** @var string[] */
    public array $warnings = [];

    /** @var array<int, array{table: string, column: string, rows: int, reason: string}> */
    public array $droppedColumns = [];

    /** @var array<string, int> source table => rows filtered out */
    public array $filtered = [];

    public function copied(string $table, int $rows): void
    {
        $this->copied[$table] = ($this->copied[$table] ?? 0) + $rows;
    }

    public function skipped(string $table, string $reason): void
    {
        $this->skipped[$table] = $reason;
    }

    public function note(string $message): void
    {
        $this->notes[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function filtered(string $table, int $rows): void
    {
        if ($rows > 0) {
            $this->filtered[$table] = ($this->filtered[$table] ?? 0) + $rows;
        }
    }

    /**
     * Record a source column with no home in the target schema. $rows counts the
     * rows where it held a value, so an empty column can be told apart from real
     * data being left behind.
     */
    public function dropColumn(string $table, string $column, int $rows, string $reason): void
    {
        $this->droppedColumns[] = ['table' => $table, 'column' => $column, 'rows' => $rows, 'reason' => $reason];
    }

    public function totalRows(): int
    {
        return array_sum($this->copied);
    }

    /**
     * Dropped columns that actually held data.
     *
     * @return array<int, array{table: string, column: string, rows: int, reason: string}>
     */
    public function lossyColumns(): array
    {
        return array_values(array_filter($this->droppedColumns, fn (array $d): bool => $d['rows'] > 0));
    }
}
