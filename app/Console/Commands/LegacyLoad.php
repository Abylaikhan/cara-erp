<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads a legacy snapshot into the separate `legacy` schema of the local database (one jsonb row per source row),
 * then verifies every table round-trip: row count and canonical content hash from the database equal the snapshot.
 * Views legacy.<table> point at the latest loaded snapshot. Application tables are not touched.
 */
class LegacyLoad extends Command
{
    protected $signature = 'legacy:load {snapshot? : Snapshot id (default: the latest)} {--force : Reload if already loaded}';

    protected $description = 'Load a legacy Supabase snapshot into the local `legacy` schema and verify it';

    public function handle(): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Legacy snapshots are loaded only into local/testing databases.');
        }
        $base = rtrim(config('legacy.snapshot_dir'), '/');
        $id = $this->argument('snapshot') ?: collect(glob("$base/*/manifest.json"))->map(fn ($p) => basename(dirname($p)))->sort()->last();
        if (! $id || ! preg_match('/^\d{8}T\d{6}Z$/', $id) || ! is_file("$base/$id/manifest.json")) {
            $this->error('Snapshot not found: '.($id ?: '(none)'));

            return self::FAILURE;
        }
        $manifest = json_decode(file_get_contents("$base/$id/manifest.json"), true, flags: JSON_THROW_ON_ERROR);

        DB::statement('CREATE SCHEMA IF NOT EXISTS legacy');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.snapshots (id text PRIMARY KEY, taken_at timestamptz NOT NULL, manifest jsonb NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        DB::statement('CREATE TABLE IF NOT EXISTS legacy.rows (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, row_no integer NOT NULL, data jsonb NOT NULL, PRIMARY KEY (snapshot_id, table_name, row_no))');

        if (DB::table('legacy.snapshots')->where('id', $id)->exists()) {
            if (! $this->option('force')) {
                $this->info("snapshot {$id} is already loaded (use --force to reload)");

                return $this->verify($id, $manifest, $base);
            }
            DB::table('legacy.snapshots')->where('id', $id)->delete();
        }

        DB::transaction(function () use ($id, $manifest, $base) {
            DB::table('legacy.snapshots')->insert(['id' => $id, 'taken_at' => $manifest['started_at'], 'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE)]);
            foreach ($manifest['tables'] as $t) {
                if (($t['status'] ?? '') !== 'OK') {
                    continue;
                }
                $batch = [];
                $rowNo = 0;
                foreach ($this->lines("$base/$id/{$t['file']}") as $line) {
                    $batch[] = ['snapshot_id' => $id, 'table_name' => $t['table'], 'row_no' => ++$rowNo, 'data' => $line];
                    if (count($batch) === 500) {
                        DB::table('legacy.rows')->insert($batch);
                        $batch = [];
                    }
                }
                if ($batch) {
                    DB::table('legacy.rows')->insert($batch);
                }
            }
        });

        foreach ($manifest['tables'] as $t) {
            if (($t['status'] ?? '') === 'OK') {
                $table = $t['table'];
                if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
                    throw new RuntimeException("Invalid table name in manifest: {$table}");
                }
                DB::statement("CREATE OR REPLACE VIEW legacy.\"{$table}\" AS SELECT row_no, data FROM legacy.rows WHERE snapshot_id = '{$id}' AND table_name = '{$table}'");
            }
        }

        return $this->verify($id, $manifest, $base);
    }

    private function verify(string $id, array $manifest, string $base): int
    {
        $failures = 0;
        foreach ($manifest['tables'] as $t) {
            if (($t['status'] ?? '') !== 'OK') {
                $this->warn(sprintf('%-26s %7s  NOT LOADED (%s)', $t['table'], '—', $t['status'] ?? '?'));
                $failures++;

                continue;
            }
            $fileHash = hash_init('sha256');
            $fileRows = 0;
            foreach ($this->lines("$base/$id/{$t['file']}") as $line) {
                hash_update($fileHash, $this->canonical(json_decode($line, true, flags: JSON_THROW_ON_ERROR))."\n");
                $fileRows++;
            }
            $dbHash = hash_init('sha256');
            $dbRows = 0;
            foreach (DB::table('legacy.rows')->where(['snapshot_id' => $id, 'table_name' => $t['table']])->orderBy('row_no')->cursor() as $row) {
                hash_update($dbHash, $this->canonical(json_decode($row->data, true, flags: JSON_THROW_ON_ERROR))."\n");
                $dbRows++;
            }
            $ok = $fileRows === $t['rows'] && $dbRows === $fileRows && hash_final($fileHash) === hash_final($dbHash);
            $failures += $ok ? 0 : 1;
            $this->line(sprintf('%-26s %7d  %s', $t['table'], $dbRows, $ok ? 'OK (count + content)' : 'MISMATCH'));
        }
        $this->info("snapshot {$id}: ".($failures ? "{$failures} table(s) not verified" : 'all tables verified').' in schema legacy');

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    /** @return \Generator<int, string> */
    private function lines(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        while (($line = fgets($fh)) !== false) {
            if (($line = rtrim($line, "\n")) !== '') {
                yield $line;
            }
        }
        fclose($fh);
    }

    /** JSON with object keys sorted recursively: jsonb reorders keys, the content must not change. */
    private function canonical(mixed $value): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v, SORT_STRING);
                }

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
