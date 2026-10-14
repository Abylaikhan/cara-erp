<?php

namespace Tests\Feature\Legacy;

use App\Legacy\SupabaseReadOnlyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/** Read-only copy of the legacy Supabase: fake PostgREST with synthetic rows (no real data in tests). */
class LegacyCopyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** @var array<string, list<array<string, mixed>>> */
    private array $data;

    /** 'once': a row appears while the first attempt reads; 'always': rows keep appearing. */
    private string $grow = 'never';

    private int $countCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/legacy-'.uniqid());
        $this->data = [
            'customers' => array_map(fn ($i) => ['id' => $i, 'name' => "Покупатель {$i}", 'total' => $i * 1.5, 'tags' => ['b' => 1, 'a' => [2, 3]]], range(1, 5)),
            'app_users' => [['id' => 1, 'login' => 'u1', 'password' => 'plain-text', 'pages' => '["sales"]'], ['id' => 2, 'login' => 'u2', 'password' => null]],
            'expense_categories' => [['code' => 'b', 'name' => 'Б'], ['code' => 'a', 'name' => 'А'], ['code' => 'c', 'name' => 'В']],
            'staff' => [['id' => 7, 'name' => 'Сотрудник', 'pin' => '1234']],
            'empty_table' => [],
        ];
        config([
            'legacy.supabase' => ['url' => 'https://testproject.supabase.co', 'key' => 'test-anon-key', 'timeout' => 5],
            'legacy.snapshot_dir' => $this->dir,
            'legacy.tables' => array_keys($this->data),
            'legacy.page_size' => 2,
            'legacy.pause_ms' => 0,
            'legacy.retry_sleep_s' => 0,
            'legacy.attempts' => 2,
        ]);
        Http::fake(fn (Request $request) => $this->postgrest($request));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function postgrest(Request $request): mixed
    {
        if ($request->method() !== 'GET') {
            return Http::response(['message' => 'write attempted'], 405);
        }
        $table = basename(parse_url($request->url(), PHP_URL_PATH));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $rows = $this->data[$table] ?? null;
        if ($rows === null) {
            return Http::response(['message' => 'relation does not exist'], 404);
        }
        if (($request->header('Prefer')[0] ?? '') === 'count=exact') {
            $this->countCalls++;
            // Counts come in pairs (before/after reading); a row inserted before the "after" count = change during read.
            $afterCount = $this->countCalls % 2 === 0;
            if ($afterCount && ($this->grow === 'always' || ($this->grow === 'once' && $this->countCalls === 2))) {
                $this->data[$table][] = ['id' => 1000 + $this->countCalls, 'name' => 'новая строка', 'total' => 0, 'tags' => []];
                $rows = $this->data[$table];
            }
        }
        if (isset($query['order'])) {
            $column = explode('.', $query['order'])[0];
            if ($rows !== [] && ! array_key_exists($column, $rows[0])) {
                return Http::response(['message' => "column {$table}.{$column} does not exist"], 400);
            }
            usort($rows, fn ($a, $b) => $a[$column] <=> $b[$column]);
        }
        [$from, $to] = array_map('intval', explode('-', $request->header('Range')[0] ?? '0-999'));
        $slice = array_slice($rows, $from, $to - $from + 1);

        return Http::response($slice, 206, ['Content-Range' => $from.'-'.($from + max(count($slice), 1) - 1).'/'.count($rows)]);
    }

    private function snapshotDir(): string
    {
        return glob($this->dir.'/*', GLOB_ONLYDIR)[0];
    }

    public function test_snapshot_reads_everything_with_get_only(): void
    {
        $this->artisan('legacy:snapshot')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/rpc/'));
        $manifest = json_decode(file_get_contents($this->snapshotDir().'/manifest.json'), true);
        $this->assertSame(['GET' => $manifest['requests']['total']], $manifest['requests']['by_method']);
        $this->assertSame(11, $manifest['totals']['rows']);
        $byTable = collect($manifest['tables'])->keyBy('table');
        $this->assertSame('id', $byTable['customers']['order_by']);
        $this->assertSame('code', $byTable['expense_categories']['order_by'], 'falls back when the table has no id');
        $this->assertSame(['OK'], $byTable->pluck('status')->unique()->values()->all());
        $lines = file($this->snapshotDir().'/customers.jsonl', FILE_IGNORE_NEW_LINES);
        $this->assertCount(5, $lines, 'pagination by 2 rows read all pages');
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($l) => json_decode($l, true)['id'], $lines));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->snapshotDir())), -4));
    }

    public function test_credentials_are_not_copied(): void
    {
        $this->artisan('legacy:snapshot')->assertSuccessful();

        $users = file_get_contents($this->snapshotDir().'/app_users.jsonl');
        $staff = file_get_contents($this->snapshotDir().'/staff.jsonl');
        $this->assertStringNotContainsString('plain-text', $users);
        $this->assertStringNotContainsString('1234', $staff);
        $this->assertNull(json_decode(explode("\n", $users)[0], true)['password']);
        $manifest = collect(json_decode(file_get_contents($this->snapshotDir().'/manifest.json'), true)['tables'])->keyBy('table');
        $this->assertSame(['password'], $manifest['app_users']['redacted_columns']);
        $this->assertSame(['pin'], $manifest['staff']['redacted_columns']);
        $this->assertStringNotContainsString('test-anon-key', file_get_contents($this->snapshotDir().'/requests.log'));
    }

    public function test_rows_changing_during_read_are_reread_then_flagged(): void
    {
        config(['legacy.tables' => ['customers']]);
        $this->grow = 'once'; // one change: the second attempt is consistent
        $this->artisan('legacy:snapshot')->assertSuccessful();
        $table = json_decode(file_get_contents($this->snapshotDir().'/manifest.json'), true)['tables'][0];
        $this->assertSame([2, 'OK', 6], [$table['attempts'], $table['status'], $table['rows']]);

        File::deleteDirectory($this->dir);
        $this->grow = 'always'; // keeps changing: reported, not hidden
        $this->countCalls = 0;
        $this->artisan('legacy:snapshot')->assertFailed();
        $table = json_decode(file_get_contents($this->snapshotDir().'/manifest.json'), true)['tables'][0];
        $this->assertSame('CHANGED_DURING_READ', $table['status']);
    }

    public function test_client_rejects_foreign_urls_and_odd_table_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SupabaseReadOnlyClient('https://evil.example.com', 'k');
    }

    public function test_client_reads_pages_in_natural_or_requested_order_only(): void
    {
        $client = new SupabaseReadOnlyClient('https://testproject.supabase.co', 'k');
        $client->page('customers', null, 0, 9);
        $client->page('customers', 'id', 0, 9, 'desc');
        $this->assertSame(['GET', 'GET'], array_column($client->log, 'method'));
        $this->assertStringNotContainsString('order=', $client->log[0]['path']);
        $this->assertStringContainsString('order=id.desc', $client->log[1]['path']);
        $this->expectException(InvalidArgumentException::class);
        $client->page('customers', 'id', 0, 9, 'desc;drop');
    }

    public function test_client_refuses_injected_table_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SupabaseReadOnlyClient('https://testproject.supabase.co', 'k'))->count('customers?select=*&delete=1');
    }

    public function test_natural_order_is_read_with_get_and_must_match_the_snapshot(): void
    {
        $this->artisan('legacy:snapshot')->assertSuccessful();
        $this->artisan('legacy:load')->assertSuccessful();
        $this->artisan('legacy:natural-order customers')->assertSuccessful()->expectsOutputToContain('other methods: 0');

        $expected = array_map(fn (array $r) => (string) $r['id'], $this->data['customers']);
        $this->assertSame($expected, DB::table('legacy.natural_order')->where('table_name', 'customers')->orderBy('position')->pluck('row_id')->all());

        $this->data['customers'][] = ['id' => 99, 'name' => 'Новая строка', 'total' => 0, 'tags' => []];
        $this->expectException(\RuntimeException::class);
        $this->artisan('legacy:natural-order customers');
    }

    public function test_load_verifies_count_and_content_and_is_idempotent(): void
    {
        $this->artisan('legacy:snapshot')->assertSuccessful();
        $this->artisan('legacy:load')->assertSuccessful()->expectsOutputToContain('all tables verified');

        $this->assertSame(5, DB::table('legacy.customers')->count());
        $this->assertSame('Покупатель 3', DB::selectOne("SELECT data->>'name' AS n FROM legacy.customers WHERE (data->>'id')::int = 3")->n);
        $this->assertSame(11, DB::table('legacy.rows')->count());

        $this->artisan('legacy:load')->assertSuccessful()->expectsOutputToContain('already loaded');
        $this->assertSame(11, DB::table('legacy.rows')->count(), 'second load adds nothing');
        $this->assertSame(0, DB::table('expense_categories')->count(), 'application tables are not touched');
    }
}
