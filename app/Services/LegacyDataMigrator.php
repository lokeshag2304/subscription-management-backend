<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LegacyDataMigrator
{
    /**
     * Run the full legacy migration and return a structured report.
     */
    public function run(): array
    {
        return [
            'ssl'        => $this->migrateTable('s_s_l_s',         ['name', 'client_name', 'expiry_date']),
            'domains'    => $this->migrateTable('domains',          ['name', 'client_name', 'expiry_date']),
            'hostings'   => $this->migrateTable('hostings',         ['name', 'client_name', 'expiry_date']),
            'counters'   => $this->migrateTable('counters',         ['name', 'client_name', 'expiry_date']),
            'emails'     => $this->migrateTable('emails',           ['name', 'client_name', 'expiry_date']),
            'tools'      => $this->migrateTable('tools',            ['name', 'client_name']),
            'users'      => $this->migrateTable('users',            ['email']),
            'activities' => $this->migrateTable('activities',       ['name', 'client_name', 'start_date']),
        ];
    }

    /**
     * Migrate a single table from legacy → current DB.
     *
     * @param  string   $table         Table name in both DBs
     * @param  string[] $uniqueKeys    Columns used to detect duplicates
     * @return array{migrated: int, skipped: int}
     */
    private function migrateTable(string $table, array $uniqueKeys): array
    {
        $migrated = 0;
        $skipped  = 0;

        try {
            // Verify legacy table exists before trying to fetch
            $legacyTables = DB::connection('legacy')
                ->select("SHOW TABLES LIKE '{$table}'");

            if (empty($legacyTables)) {
                return ['migrated' => 0, 'skipped' => 0, 'note' => "Legacy table '{$table}' not found – skipped"];
            }

            $legacyRows = DB::connection('legacy')->table($table)->get();

            DB::beginTransaction();

            foreach ($legacyRows as $row) {
                $rowArr = (array) $row;

                // Build duplicate-check query
                $query = DB::table($table);
                foreach ($uniqueKeys as $key) {
                    if (array_key_exists($key, $rowArr)) {
                        $query->where($key, $rowArr[$key]);
                    }
                }

                if ($query->exists()) {
                    $skipped++;
                    continue;
                }

                // Strip the id so auto-increment assigns a fresh one in current DB
                unset($rowArr['id']);

                DB::table($table)->insert($rowArr);
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
}
