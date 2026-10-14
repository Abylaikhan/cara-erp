<?php

namespace App\Console\Commands;

use App\Legacy\SupabaseReadOnlyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Natural (insertion) order of a legacy table, read with GET only. Some legacy pages show rows unsorted — e.g. the lines of
 * an ESF in the order they were inserted from the XML — and such tables have no column to sort by, so the snapshot
 * (read in key order) cannot keep it. The order is stored next to the loaded snapshot; the set of rows must be the same.
 */
class LegacyNaturalOrder extends Command
{
    protected $signature = 'legacy:natural-order {table : Legacy table, e.g. incoming_invoice_items} {--snapshot= : Snapshot id (default: the latest loaded)}';

    protected $description = 'Read the natural row order of a legacy table (GET only) and store it next to the loaded snapshot';

    public function handle(): int
    {
        $table = (string) $this->argument('table');
        $snapshot = $this->option('snapshot') ?: DB::table('legacy.snapshots')->max('id');
        if (! $snapshot) {
            $this->error('No loaded snapshot: run legacy:snapshot and legacy:load first.');

            return self::FAILURE;
        }
        $cfg = config('legacy');
        $client = new SupabaseReadOnlyClient((string) $cfg['supabase']['url'], (string) $cfg['supabase']['key'], (int) $cfg['supabase']['timeout']);
        $read = function () use ($client, $table, $cfg): array {
            $ids = [];
            for ($from = 0; ; $from += (int) $cfg['page_size']) {
                $page = $client->page($table, null, $from, $from + (int) $cfg['page_size'] - 1);
                foreach ($page as $row) {
                    $ids[] = (string) $row['id'];
                }
                if (count($page) < (int) $cfg['page_size']) {
                    return $ids;
                }
                usleep((int) $cfg['pause_ms'] * 1000);
            }
        };
        // Two reads must agree: the natural order is only meaningful while nobody writes to the table.
        $ids = $read();
        if ($ids !== $read()) {
            throw new RuntimeException("{$table}: the natural order changed between two reads — try again later.");
        }
        $snapshotIds = DB::table('legacy.rows')->where(['snapshot_id' => $snapshot, 'table_name' => $table])->selectRaw("data->>'id' AS row_id")->pluck('row_id')->all();
        if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $snapshotIds) || array_diff($snapshotIds, $ids)) {
            throw new RuntimeException("{$table}: the rows differ from snapshot {$snapshot} — take a new snapshot first.");
        }

        DB::transaction(function () use ($snapshot, $table, $ids) {
            DB::statement('CREATE TABLE IF NOT EXISTS legacy.natural_order (snapshot_id text NOT NULL REFERENCES legacy.snapshots(id) ON DELETE CASCADE, table_name text NOT NULL, position integer NOT NULL, row_id text NOT NULL, PRIMARY KEY (snapshot_id, table_name, position), UNIQUE (snapshot_id, table_name, row_id))');
            DB::table('legacy.natural_order')->where(['snapshot_id' => $snapshot, 'table_name' => $table])->delete();
            foreach (array_chunk($ids, 1000, true) as $chunk) {
                DB::table('legacy.natural_order')->insert(array_map(fn (int $pos, string $id) => ['snapshot_id' => $snapshot, 'table_name' => $table, 'position' => $pos + 1, 'row_id' => $id], array_keys($chunk), $chunk));
            }
        });
        $this->info(sprintf('%s: natural order of %d rows stored for snapshot %s (%d GET requests, other methods: 0)', $table, count($ids), $snapshot, count($client->log)));

        return self::SUCCESS;
    }
}
