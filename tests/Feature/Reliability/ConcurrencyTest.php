<?php

namespace Tests\Feature\Reliability;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ERP-072, AC-73 «Два пользователя меняют документ/остаток: конфликт обработан или операции последовательно проведены,
 * изменения не потеряны». Every edit of a record carries the version the user saw; the service locks the row
 * (SELECT … FOR UPDATE) and only then compares the version, so of two saves at the same moment the second waits for the
 * first and is refused as stale — never written over it. Proven here with a real second session, and guarded for every
 * service.
 */
class ConcurrencyTest extends TestCase
{
    /**
     * The other session must see committed rows, so no test transaction wraps this one: the schema is made as RefreshDatabase
     * makes it, and the rows the test commits are deleted at its end (the other tests find the database as they expect).
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
    }

    public function test_a_save_racing_another_users_save_waits_and_is_refused_not_lost(): void
    {
        $customer = (new Customer)->forceFill(['name' => 'Демо покупатель', 'type' => 'client', 'channel' => 'office']);
        $customer->save();
        $seen = $customer->fresh()->lock_version; // the version both users opened
        $user = User::factory()->role('director')->create();
        try {
            $this->race($customer, $seen, $user);
        } finally {
            DB::table('audit_events')->where('user_id', $user->id)->delete();
            DB::table('customers')->where('id', $customer->id)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    private function race(Customer $customer, int $seen, User $user): void
    {
        $db = config('database.connections.pgsql_test');
        // The other user's request: holds the row, saves its change after 1.5 s and commits.
        $other = <<<'PHP'
            $pdo = new PDO(getenv('RACE_DSN'), getenv('RACE_USER'), getenv('RACE_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $id = (int) getenv('RACE_ID');
            $pdo->beginTransaction();
            $pdo->query("SELECT id FROM customers WHERE id = {$id} FOR UPDATE");
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT); // a pipe is buffered: the test must know now
            usleep(1500000);
            $pdo->exec("UPDATE customers SET name = 'Демо другого пользователя', lock_version = lock_version + 1 WHERE id = {$id}");
            $pdo->commit();
            fwrite(STDOUT, "committed\n");
            PHP;
        $process = proc_open(['php', '-r', $other], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(),
            'RACE_DSN' => "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}", 'RACE_USER' => $db['username'],
            'RACE_PASSWORD' => (string) $db['password'], 'RACE_ID' => (string) $customer->id]);
        if (fgets($pipes[1]) !== "locked\n") {
            $this->fail('the other session did not start: '.stream_get_contents($pipes[2])); // read only now: it waits for the end
        }

        $started = microtime(true);
        try {
            app(CustomerService::class)->update($customer->id, ['name' => 'Демо моё', 'type' => 'client'], $seen, $user);
            $this->fail('a stale save must be refused');
        } catch (ValidationException $e) {
            $this->assertSame([CustomerService::STALE], $e->errors()['lock_version']);
        }
        $waited = microtime(true) - $started;
        $this->assertSame("committed\n", fgets($pipes[1]));
        proc_close($process);

        $this->assertGreaterThan(1.0, $waited, 'the save waited for the row held by the other one');
        $this->assertSame(['Демо другого пользователя', $seen + 1], [$customer->fresh()->name, $customer->fresh()->lock_version], 'the other change is kept');
    }

    public function test_every_version_check_of_a_service_follows_a_row_lock(): void
    {
        $unlocked = [];
        foreach (glob(app_path('Services/*.php')) as $file) {
            $src = (string) file_get_contents($file);
            // Helpers that lock the row they return (locked(), lockActive(), lockedDealer()…).
            preg_match_all('/function (\w+)\([^)]*\)[^{]*\{(?:(?!\n    (?:public|private|protected) function).)*?lockForUpdate/s', $src, $m);
            $helpers = $m[1];
            preg_match_all('/lock_version !==? \$lockVersion/', $src, $checks, PREG_OFFSET_CAPTURE);
            foreach ($checks[0] as [, $offset]) {
                $method = substr($src, (int) strrpos(substr($src, 0, $offset), ' function '), $offset - (int) strrpos(substr($src, 0, $offset), ' function '));
                $locked = str_contains($method, 'lockForUpdate') || collect($helpers)->contains(fn (string $h) => str_contains($method, "->{$h}(") || str_contains($method, "::{$h}("));
                if (! $locked) {
                    $unlocked[] = basename($file).' at offset '.$offset;
                }
            }
        }
        $this->assertSame([], $unlocked, 'compare the version only after SELECT … FOR UPDATE of the row');
    }
}
