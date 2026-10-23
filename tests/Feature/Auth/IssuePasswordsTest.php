<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** D-13, answer 2: new passwords for the active accounts, written once to a file only its owner may read. */
class IssuePasswordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_passwords_replace_the_old_ones_and_go_to_the_file_only(): void
    {
        $a = User::factory()->role('director')->create(['login' => 'director', 'password' => 'Старый-Демо-1']);
        $b = User::factory()->role('manager')->create(['login' => 'manager', 'password' => 'Старый-Демо-2']);
        $off = User::factory()->role('buyer')->create(['login' => 'buyer', 'password' => 'Старый-Демо-3', 'is_active' => false]);
        $out = sys_get_temp_dir().'/promtech-passwords-'.bin2hex(random_bytes(4)).'.tsv';

        $this->artisan('users:issue-passwords', ['--out' => $out, '--force' => true])->assertSuccessful()->doesntExpectOutputToContain("\t");

        $lines = array_map(fn ($l) => explode("\t", $l), array_slice(file($out, FILE_IGNORE_NEW_LINES), 1));
        $this->assertSame(['director', 'manager'], array_column($lines, 0), 'active accounts only');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($out)), -4));
        foreach ([$a, $b] as $k => $user) {
            $password = $lines[$k][1];
            $this->assertSame(14, strlen($password));
            $this->assertTrue(Hash::check($password, $user->fresh()->password));
            $this->assertFalse(Hash::check('Старый-Демо-'.($k + 1), $user->fresh()->password));
        }
        $this->assertTrue(Hash::check('Старый-Демо-3', $off->fresh()->password), 'a blocked account is left as it is');
        $this->assertDatabaseHas('audit_events', ['action' => 'users.passwords_issued']);
        $this->assertStringNotContainsString($lines[0][1], (string) DB::table('audit_events')->where('action', 'users.passwords_issued')->value('changes'));

        // the file is never overwritten
        $this->artisan('users:issue-passwords', ['--out' => $out, '--force' => true])->assertExitCode(2);
        unlink($out);
    }
}
