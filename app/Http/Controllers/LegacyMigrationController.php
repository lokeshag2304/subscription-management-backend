<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LegacyMigrationController extends Controller
{
    /**
     * Tables to migrate - same name in legacy and current DB.
     * Key = table name used in the JSON report.
     * Value = array of column names to carry across.
     */
    private array $tables = [
        's_s_l_s'    => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'domains'    => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'hostings'   => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'counters'   => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'emails'     => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'tools'      => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
        'users'      => ['id', 'name', 'email', 'password', 'created_at', 'updated_at'],
        'activities' => ['id', 'name', 'client_name', 'amount', 'start_date', 'expiry_date', 'status', 'remarks', 'created_at', 'updated_at'],
    ];

    /**
     * POST /api/migrate-legacy-data
     */
    public function migrate(): JsonResponse
    {
        $report = [];

        foreach ($this->tables as $table => $columns) {
            $report[$table] = $this->migrateTable($table, $columns);
        }

        $hasErrors = collect($report)->contains(fn($r) => isset($r['error']));

        return response()->json([
            'status'  => !$hasErrors,
            'message' => $hasErrors
                ? 'Migration completed with some errors. Check data for details.'
                : 'Legacy migration completed successfully.',
            'data'    => $report,
        ], $hasErrors ? 207 : 200);
    }

    // -----------------------------------------------------------------

    private function migrateTable(string $table, array $columns): array
    {
        $migrated = 0;
        $skipped  = 0;

        try {
            // ── 1. Confirm legacy table exists ────────────────────────────
            $legacyExists = DB::connection('legacy')
                ->select("SHOW TABLES LIKE '{$table}'");

            if (empty($legacyExists)) {
                return [
                    'migrated' => 0,
                    'skipped'  => 0,
                    'note'     => "Table '{$table}' not found in legacy DB – skipped.",
                ];
            }

            // ── 2. Confirm current table exists ───────────────────────────
            $currentExists = DB::select("SHOW TABLES LIKE '{$table}'");

            if (empty($currentExists)) {
                return [
                    'migrated' => 0,
                    'skipped'  => 0,
                    'note'     => "Table '{$table}' not found in current DB – skipped.",
                ];
            }

            // ── 3. Discover columns that actually exist in BOTH tables ─────
            $legacyCols  = $this->getColumns('legacy', $table);
            $currentCols = $this->getColumns('mysql', $table);
            $validCols   = array_values(
                array_intersect($columns, $legacyCols, $currentCols)
            );

            if (empty($validCols)) {
                return [
                    'migrated' => 0,
                    'skipped'  => 0,
                    'note'     => "No common columns found for '{$table}' – skipped.",
                ];
            }

            // ── 4. Fetch legacy rows ──────────────────────────────────────
            $legacyRows = DB::connection('legacy')
                ->table($table)
                ->select($validCols)
                ->get();

            // ── 5. Build an in-memory set of existing IDs for fast lookup ─
            $existingIds = in_array('id', $validCols)
                ? DB::table($table)->pluck('id')->flip()->all()
                : [];

            // ── 6. Insert inside a transaction ────────────────────────────
            DB::beginTransaction();

            foreach ($legacyRows as $legacyRow) {
                $row = (array) $legacyRow;

                // ID-based duplicate check (idempotent)
                if (isset($row['id']) && array_key_exists($row['id'], $existingIds)) {
                    $skipped++;
                    continue;
                }

                DB::table($table)->insert($row);

                // Register newly inserted id so we don't double-insert within
                // the same run if the source has duplicates
                if (isset($row['id'])) {
                    $existingIds[$row['id']] = true;
                }

                $migrated++;
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();

            return [
                'migrated' => $migrated,
                'skipped'  => $skipped,
                'error'    => $e->getMessage(),
            ];
        }

        return ['migrated' => $migrated, 'skipped' => $skipped];
    }

    /**
     * Return column names for a given connection + table.
     */
    private function getColumns(string $connection, string $table): array
    {
        $cols = DB::connection($connection)
            ->select("SHOW COLUMNS FROM `{$table}`");

        return array_column(
            array_map(fn($c) => (array) $c, $cols),
            'Field'
        );
    }
}
