<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DatabaseDatetimeShiftService
{
    public function planLegacyShift(CarbonInterface $before, int $minutes = 330, array $tables = []): array
    {
        return $this->run($before, $minutes, $tables, true);
    }

    public function shiftLegacy(CarbonInterface $before, int $minutes = 330, array $tables = []): array
    {
        return $this->run($before, $minutes, $tables, false);
    }

    private function run(CarbonInterface $before, int $minutes, array $tables, bool $dryRun): array
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
            ];
        }

        return $results;
    }

    private function quoteIdentifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
