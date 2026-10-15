<?php

namespace Tests\Feature\Ops;

use App\Models\Product;
use App\Models\ProjectFile;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** app:view-digest — what every user sees, as checksums, recorded at the copy and compared on the restored application. */
class ViewDigestTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-10-16T00:00:00Z';

    private string $out;

    private User $director;

    private User $storekeeper;

    private ProjectFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->out = sys_get_temp_dir().'/view-digest-'.bin2hex(random_bytes(4)).'.tsv';
        $this->director = User::factory()->role('director')->create(['login' => 'demo_director', 'name' => 'Демо Директор']);
        $this->storekeeper = User::factory()->role('storekeeper')->create(['login' => 'demo_storekeeper', 'name' => 'Демо Кладовщик']);
        User::factory()->role('manager')->inactive()->create(['login' => 'demo_blocked', 'name' => 'Демо Заблокирован']);
        Product::create(['name' => 'Демо стол', 'category' => 'Столы', 'price_retail' => '1000.00', 'price_wholesale' => '800.00', 'unit' => 'шт']);
        $service = app(ProjectService::class);
        $project = $service->create(['title' => 'Демо проект', 'channel' => 'office'], $this->director);
        $this->file = $service->addFile($project->id, 'designer', false, UploadedFile::fake()->createWithContent('demo.txt', 'демо-содержимое'), $this->director);
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    /** @return array<string, list<string>> «user<TAB>target» → [status, checksum] */
    private function lines(): array
    {
        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->out))) as $line) {
            [$user, $target, $status, $digest] = explode("\t", $line);
            $lines["{$user}\t{$target}"] = [$status, $digest];
        }

        return $lines;
    }

    private function record(): void
    {
        $this->artisan('app:view-digest', ['--at' => self::AT, '--out' => $this->out])->assertSuccessful();
    }

    public function test_every_active_user_page_and_file_is_recorded_and_the_same_database_compares_equal(): void
    {
        $this->record();

        $lines = $this->lines();
        $d = $this->director->id;
        $s = $this->storekeeper->id;
        $this->assertSame('200', $lines["{$d}\t/catalog"][0]);
        $this->assertSame(['200', hash('sha256', 'демо-содержимое')], $lines["{$d}\tfile:{$this->file->id}"], 'the bytes of the file');
        $this->assertSame(['403', '-'], $lines["{$s}\t/crm"], 'a forbidden page is a refusal');
        $this->assertArrayNotHasKey("{$s}\tfile:{$this->file->id}", $lines, 'files are asked only by users with the CRM');
        $this->assertSame('302', $lines["{$d}\t/"][0]);
        $this->assertSame(['200', '302'], [$lines["guest\t/login"][0], $lines["guest\t/dashboard"][0]]);
        $users = array_values(array_unique(array_map(fn (string $k) => strstr($k, "\t", true), array_keys($lines))));
        sort($users);
        $this->assertSame([(string) $d, (string) $s, 'guest'], $users, 'the blocked user is not asked');
        $this->assertSame(0600, fileperms($this->out) & 0777);

        $this->artisan('app:view-digest', ['--at' => self::AT, '--compare' => $this->out])->assertSuccessful()->expectsOutputToContain('answers match');
    }

    public function test_a_changed_sum_a_lost_file_and_a_changed_right_are_differences(): void
    {
        $this->record();
        Product::query()->update(['price_retail' => '1000.01']);
        Storage::disk('local')->delete($this->file->storage_path);
        $this->storekeeper->forceFill(['role' => 'manager'])->save();

        $this->artisan('app:view-digest', ['--at' => self::AT, '--compare' => $this->out])->assertFailed()
            ->expectsOutputToContain("{$this->director->id}\t/catalog: content differs")
            ->expectsOutputToContain("{$this->director->id}\tfile:{$this->file->id}: HTTP 200 → 404")
            ->expectsOutputToContain("{$this->storekeeper->id}\t/crm: HTTP 403 → 200")
            ->expectsOutputToContain('difference(s) between the copy and the restored application');
    }

    public function test_the_clock_is_frozen_at_the_recorded_moment(): void
    {
        $this->record();
        Carbon::setTestNow('2026-11-20 12:00:00');

        $this->artisan('app:view-digest', ['--at' => self::AT, '--compare' => $this->out])->assertSuccessful();
        $this->artisan('app:view-digest', ['--at' => '2026-11-20T12:00:00Z', '--compare' => $this->out])->assertFailed()
            ->expectsOutputToContain("{$this->director->id}\t/dashboard: content differs");
        $this->assertSame('2026-11-20 12:00:00', now()->format('Y-m-d H:i:s'), 'the clock of the caller is given back');
    }

    public function test_pages_are_read_without_writing_sessions_cache_or_audit(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        $count = fn () => [DB::table('sessions')->count(), DB::table('cache')->count(), DB::table('audit_events')->count(), DB::table('jobs')->count()];
        $before = $count();

        $this->record();

        $this->assertSame($before, $count());
    }

    public function test_a_copy_with_other_migrations_is_not_compared(): void
    {
        $this->record();
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_from_a_newer_code', 'batch' => 99]);

        $this->artisan('app:view-digest', ['--at' => self::AT, '--compare' => $this->out])->assertFailed()
            ->expectsOutputToContain('migrations: 1 applied in the copy are not in this code');
    }
}
