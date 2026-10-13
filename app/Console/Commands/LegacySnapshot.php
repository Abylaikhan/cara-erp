<?php

namespace App\Console\Commands;

use App\Legacy\LegacyReadException;
use App\Legacy\SupabaseReadOnlyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Read-only copy of the legacy Supabase tables (GET only) into .local-audit/supabase/<snapshot>/.
 * Every table is read in a stable order and checked against the exact server count before and after reading;
 * if rows changed meanwhile the table is read again. Credential columns are not copied. No row data is printed.
 */
class LegacySnapshot extends Command
{
    protected $signature = 'legacy:snapshot {--table=* : Only these tables}';

    protected $description = 'Read-only copy of the legacy Supabase tables into the local snapshot directory';

    public function handle(): int
    {
        $cfg = config('legacy');
        $client = new SupabaseReadOnlyClient((string) $cfg['supabase']['url'], (string) $cfg['supabase']['key'], (int) $cfg['supabase']['timeout']);
        $tables = $this->option('table') ?: $cfg['tables'];
        $unknown = array_diff($tables, $cfg['tables']);
        if ($unknown) {
            $this->error('Not in config/legacy.php: '.implode(', ', $unknown));

            return self::INVALID;
        }

        $id = now('UTC')->format('Ymd\THis\Z');
        $dir = rtrim($cfg['snapshot_dir'], '/').'/'.$id;
        File::ensureDirectoryExists($dir, 0700);
        chmod(dirname($dir), 0700);
        chmod($dir, 0700);
        $started = now('UTC');
        $results = [];
        foreach ($tables as $table) {
            $results[] = $result = $this->copyTable($client, $table, $dir, $cfg);
            $this->line(sprintf('%-26s %7d  %s', $table, $result['rows'], $result['status']));
        }

        $methods = array_count_values(array_column($client->log, 'method'));
        $manifest = [
            'schema' => 'promtech.legacy-snapshot/1',
            'snapshot_id' => $id,
            'source' => ['kind' => 'supabase-postgrest', 'host' => parse_url($cfg['supabase']['url'], PHP_URL_HOST), 'role' => 'anon', 'access' => 'read-only (GET)'],
            'started_at' => $started->toIso8601ZuluString(),
            'finished_at' => now('UTC')->toIso8601ZuluString(),
            'requests' => ['total' => count($client->log), 'by_method' => $methods],
            'tables' => $results,
            'totals' => ['tables' => count($results), 'rows' => array_sum(array_column($results, 'rows'))],
        ];
        file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents("$dir/requests.log", implode("\n", array_map(fn ($r) => "{$r['method']} {$r['path']} {$r['status']} {$r['ms']}ms {$r['bytes']}B", $client->log))."\n");
        chmod("$dir/manifest.json", 0600);
        chmod("$dir/requests.log", 0600);

        if (($methods['GET'] ?? 0) !== count($client->log)) {
            throw new RuntimeException('Non-GET request detected — this must never happen.');
        }
        $failed = array_filter($results, fn ($r) => $r['status'] !== 'OK');
        $this->info("snapshot {$id}: {$manifest['totals']['tables']} tables, {$manifest['totals']['rows']} rows, {$manifest['requests']['total']} GET requests, other methods: 0");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function copyTable(SupabaseReadOnlyClient $client, string $table, string $dir, array $cfg): array
    {
        $result = ['table' => $table, 'rows' => 0, 'status' => 'FAILED'];
        for ($attempt = 1; $attempt <= $cfg['attempts']; $attempt++) {
            try {
                $before = $client->count($table);
                $order = $before > 0 ? $client->orderColumn($table, $cfg['order_candidates']) : null;
                $part = "$dir/$table.jsonl.part";
                $fh = fopen($part, 'w');
                chmod($part, 0600);
                $hash = hash_init('sha256');
                $fetched = 0;
                $columns = $redacted = $orderValues = [];
                $duplicates = 0;
                for ($from = 0; ;) {
                    $page = $client->page($table, $order, $from, $from + $cfg['page_size'] - 1);
                    if ($page === []) {
                        break;
                    }
                    foreach ($page as $row) {
                        foreach ($row as $column => $value) {
                            $columns[$column] = true;
                            if ($value !== null && preg_match($cfg['redact_columns'], (string) $column)) {
                                $row[$column] = null;
                                $redacted[$column] = true;
                            }
                        }
                        if ($order === 'id' && isset($row['id'])) {
                            $duplicates += isset($orderValues[$row['id']]) ? 1 : 0;
                            $orderValues[$row['id']] = true;
                        }
                        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                        hash_update($hash, $line."\n");
                        fwrite($fh, $line."\n");
                        $fetched++;
                    }
                    $from += count($page);
                    if ($from >= $before + $cfg['page_size']) {
                        break; // never loop forever on a misbehaving server
                    }
                    usleep($cfg['pause_ms'] * 1000);
                }
                fclose($fh);
                $after = $client->count($table);
                $result = [
                    'table' => $table, 'rows' => $fetched, 'count_before' => $before, 'count_after' => $after,
                    'order_by' => $order, 'duplicates' => $duplicates, 'attempts' => $attempt,
                    'columns' => array_keys($columns), 'redacted_columns' => array_keys($redacted),
                    'sha256' => hash_final($hash), 'file' => "$table.jsonl",
                    'status' => ($fetched === $before && $before === $after && $duplicates === 0) ? 'OK' : 'CHANGED_DURING_READ',
                ];
                if ($result['status'] === 'OK') {
                    rename($part, "$dir/$table.jsonl");
                    if ($order === null && $before > $cfg['page_size']) {
                        $result['note'] = 'no order column: pages read unordered, verified by exact counts';
                    }

                    return $result;
                }
            } catch (LegacyReadException $e) {
                $result = ['table' => $table, 'rows' => 0, 'status' => 'FAILED', 'error' => "HTTP {$e->status}", 'attempts' => $attempt];
            }
            sleep($cfg['retry_sleep_s'] ?? 2);
        }

        return $result;
    }
}
