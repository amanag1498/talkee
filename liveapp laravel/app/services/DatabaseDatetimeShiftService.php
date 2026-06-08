<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseDatetimeShiftService
{
    public function planLegacyShift(CarbonInterface $before, int $minutes = 330, array $tables = []): array
    {
        return $this->runBefore($before, $minutes, $tables, true);
    }

    public function shiftLegacy(CarbonInterface $before, int $minutes = 330, array $tables = []): array
    {
        return $this->runBefore($before, $minutes, $tables, false);
    }

    public function planReverseShift(CarbonInterface $before, int $minutes = 330, array $tables = [], ?int $windowMinutes = null): array
    {
        return $this->runReverse($before, $minutes, $tables, true, $windowMinutes);
    }

    public function reverseShift(CarbonInterface $before, int $minutes = 330, array $tables = [], ?int $windowMinutes = null): array
    {
        return $this->runReverse($before, $minutes, $tables, false, $windowMinutes);
    }

    private function runBefore(CarbonInterface $before, int $minutes, array $tables, bool $dryRun): array
    {
        if ($minutes === 0) {
            throw new InvalidArgumentException('Shift minutes cannot be zero.');
        }

        $database = DB::connection()->getDatabaseName();
        if (!$database) {
            throw new InvalidArgumentException('Unable to resolve current database name.');
        }

        $normalizedTables = collect($tables)
            ->filter(fn ($table) => is_string($table) && trim($table) !== '')
            ->map(fn ($table) => trim($table))
            ->unique()
            ->values()
            ->all();

        $columns = collect(DB::select(
            "
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND DATA_TYPE IN ('datetime', 'timestamp')
            ORDER BY TABLE_NAME, DATA_TYPE, ORDINAL_POSITION
            ",
            [$database]
        ))->map(fn ($row) => [
            'table' => (string) $row->TABLE_NAME,
            'column' => (string) $row->COLUMN_NAME,
            'data_type' => (string) $row->DATA_TYPE,
        ]);

        if ($normalizedTables !== []) {
            $columns = $columns->filter(fn (array $row) => in_array($row['table'], $normalizedTables, true))->values();
        }

        $results = [];
        $beforeSql = $before->format('Y-m-d H:i:s');
        foreach ($columns as $column) {
            $table = $column['table'];
            $field = $column['column'];
            $dataType = $column['data_type'];
            $qualifiedTable = $this->quoteIdentifier($table);
            $qualifiedField = $this->quoteIdentifier($field);

            $matching = (int) DB::table($table)
                ->whereNotNull($field)
                ->where($field, '<', $beforeSql)
                ->count();
            $affected = 0;

            if (!$dryRun && $matching > 0) {
                $affected = DB::affectingStatement(
                    "UPDATE {$qualifiedTable}
                     SET {$qualifiedField} = DATE_ADD({$qualifiedField}, INTERVAL ? MINUTE)
                     WHERE {$qualifiedField} IS NOT NULL
                       AND {$qualifiedField} < ?",
                    [$minutes, $beforeSql]
                );
            }

            $results[] = [
                'table' => $table,
                'column' => $field,
                'data_type' => $dataType,
                'before' => $beforeSql,
                'matching_rows' => $matching,
                'affected_rows' => $dryRun ? 0 : $affected,
                'minutes' => $minutes,
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'operation' => 'shift_before',
            ];
        }

        return $results;
    }

    private function runReverse(CarbonInterface $before, int $minutes, array $tables, bool $dryRun, ?int $windowMinutes = null): array
    {
        if ($minutes === 0) {
            throw new InvalidArgumentException('Shift minutes cannot be zero.');
        }
        $windowMinutes = $windowMinutes ?? abs($minutes);
        if ($windowMinutes <= 0) {
            throw new InvalidArgumentException('Reverse window minutes must be greater than zero.');
        }

        $database = DB::connection()->getDatabaseName();
        if (!$database) {
            throw new InvalidArgumentException('Unable to resolve current database name.');
        }

        $normalizedTables = collect($tables)
            ->filter(fn ($table) => is_string($table) && trim($table) !== '')
            ->map(fn ($table) => trim($table))
            ->unique()
            ->values()
            ->all();

        $columns = collect(DB::select(
            "
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
              AND DATA_TYPE IN ('datetime', 'timestamp')
            ORDER BY TABLE_NAME, DATA_TYPE, ORDINAL_POSITION
            ",
            [$database]
        ))->map(fn ($row) => [
            'table' => (string) $row->TABLE_NAME,
            'column' => (string) $row->COLUMN_NAME,
            'data_type' => (string) $row->DATA_TYPE,
        ]);

        if ($normalizedTables !== []) {
            $columns = $columns->filter(fn (array $row) => in_array($row['table'], $normalizedTables, true))->values();
        }

        $results = [];
        $beforeSql = $before->format('Y-m-d H:i:s');
        $reverseUpperSql = $before->copy()->addMinutes($windowMinutes)->format('Y-m-d H:i:s');

        foreach ($columns as $column) {
            $table = $column['table'];
            $field = $column['column'];
            $dataType = $column['data_type'];
            $qualifiedTable = $this->quoteIdentifier($table);
            $qualifiedField = $this->quoteIdentifier($field);

            $matching = (int) DB::table($table)
                ->whereNotNull($field)
                ->where($field, '>=', $beforeSql)
                ->where($field, '<', $reverseUpperSql)
                ->count();
            $affected = 0;

            if (!$dryRun && $matching > 0) {
                $affected = DB::affectingStatement(
                    "UPDATE {$qualifiedTable}
                     SET {$qualifiedField} = DATE_SUB({$qualifiedField}, INTERVAL ? MINUTE)
                     WHERE {$qualifiedField} IS NOT NULL
                       AND {$qualifiedField} >= ?
                       AND {$qualifiedField} < ?",
                    [$minutes, $beforeSql, $reverseUpperSql]
                );
            }

            $results[] = [
                'table' => $table,
                'column' => $field,
                'data_type' => $dataType,
                'before' => $beforeSql,
                'reverse_upper' => $reverseUpperSql,
                'matching_rows' => $matching,
                'affected_rows' => $dryRun ? 0 : $affected,
                'minutes' => $minutes,
                'window_minutes' => $windowMinutes,
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'operation' => 'reverse_shift_window',
            ];
        }

        return $results;
    }

    private function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
