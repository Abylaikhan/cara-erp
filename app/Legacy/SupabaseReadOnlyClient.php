<?php

namespace App\Legacy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only PostgREST client for the legacy Supabase. It can only send GET requests to /rest/v1/<table>:
 * there is no method for POST/PATCH/DELETE or /rpc, so data in the legacy database cannot be changed.
 */
final class SupabaseReadOnlyClient
{
    /** @var list<array{method: string, path: string, status: int, ms: int, bytes: int}> */
    public array $log = [];

    public function __construct(private readonly string $url, private readonly string $key, private readonly int $timeout = 60)
    {
        if (! preg_match('#^https://[a-z0-9-]+\.supabase\.(co|in)$#', rtrim($url, '/'))) {
            throw new InvalidArgumentException('LEGACY_SUPABASE_URL must be https://<project>.supabase.co');
        }
        if ($key === '') {
            throw new InvalidArgumentException('LEGACY_SUPABASE_ANON_KEY is empty');
        }
    }

    /** Exact number of rows right now (PostgREST count=exact). */
    public function count(string $table): int
    {
        $response = $this->get($table, ['select' => '*'], ['Prefer' => 'count=exact', 'Range' => '0-0']);

        return (int) Str::afterLast((string) $response->header('Content-Range'), '/');
    }

    /** First candidate column the table accepts in `order=`; null when none exists. */
    public function orderColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            try {
                $this->get($table, ['select' => $column, 'order' => "{$column}.asc"], ['Range' => '0-0']);

                return $column;
            } catch (LegacyReadException $e) {
                if ($e->status !== 400) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * One page of rows. Without `$order` PostgREST returns the table's natural (insertion) order, as legacy pages read it.
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $table, ?string $order, int $from, int $to, string $direction = 'asc'): array
    {
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Invalid order direction: {$direction}");
        }
        // $order is a column (the direction follows) or a whole list like «year.desc,month.desc», as a legacy page asks for it.
        if ($order && str_contains($order, '.') && ! preg_match('/^[a-z_][a-z0-9_]*\.(asc|desc)(,[a-z_][a-z0-9_]*\.(asc|desc))*$/', $order)) {
            throw new InvalidArgumentException("Invalid order: {$order}");
        }
        $query = ['select' => '*'] + ($order ? ['order' => str_contains($order, '.') ? $order : "{$order}.{$direction}"] : []);

        return $this->get($table, $query, ['Range' => "{$from}-{$to}", 'Prefer' => 'count=none'])->json() ?? [];
    }

    private function get(string $table, array $query, array $headers): Response
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
        $started = hrtime(true);
        $response = Http::withHeaders(['apikey' => $this->key, 'Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/json'] + $headers)
            ->timeout($this->timeout)
            ->withOptions(['allow_redirects' => false])
            ->retry(3, 1000, fn (Throwable $e) => $e instanceof ConnectionException || ($e->response?->status() ?? 0) >= 500 || $e->response?->status() === 429, throw: false)
            ->get(rtrim($this->url, '/').'/rest/v1/'.$table, $query);
        $this->log[] = [
            'method' => 'GET',
            'path' => "/rest/v1/{$table}?".http_build_query($query),
            'status' => $response->status(),
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'bytes' => strlen($response->body()),
        ];
        if (! $response->successful()) {
            throw new LegacyReadException($table, $response->status(), $response->body());
        }

        return $response;
    }
}
