<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D-13, answer 2 (23.10.2026): new passwords for everyone at the launch. Every active account (or those named) gets a new
 * random password; the logins and passwords are written once to a file only its owner may read, for handing out — they are
 * never printed, logged or stored anywhere else (the database keeps the hashes). The old sessions end.
 */
class UsersIssuePasswords extends Command
{
    protected $signature = 'users:issue-passwords {--out= : File to write the logins and new passwords to (must not exist)} {--login=* : Only these logins} {--force : Do not ask}';

    protected $description = 'Issue new random passwords to the active accounts and write them to a file only its owner may read';

    /** No look-alike characters (0/O, 1/l/I): passwords are read out and typed by people. */
    private const ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 14;

    public function handle(): int
    {
        $out = (string) $this->option('out');
        if ($out === '' || file_exists($out)) {
            $this->error('--out must name a file that does not exist yet.');

            return self::INVALID;
        }
        $query = User::query()->where('is_active', true)->orderBy('login');
        if ($this->option('login')) {
            $query->whereIn('login', (array) $this->option('login'));
        }
        $users = $query->get();
        if ($users->isEmpty()) {
            $this->error('No active account to issue a password to.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Issue new passwords to {$users->count()} accounts? Their current passwords stop working.")) {
            return self::FAILURE;
        }

        $issued = [];
        DB::transaction(function () use ($users, &$issued) {
            foreach ($users as $user) {
                $password = self::password();
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $issued[] = $user->login."\t".$password;
            }
            Audit::record('users.passwords_issued', 'users', null, ['logins' => $users->pluck('login')->all()], null);
        });

        $old = umask(0077);
        try {
            if (file_put_contents($out, "login\tpassword\n".implode("\n", $issued)."\n", LOCK_EX) === false) {
                throw new \RuntimeException("Cannot write {$out}");
            }
        } finally {
            umask($old);
        }
        chmod($out, 0600);
        $this->info(count($issued)." passwords issued; written to {$out} (owner only). Hand them out and delete the file.");

        return self::SUCCESS;
    }

    private static function password(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        return implode('', array_map(fn () => self::ALPHABET[random_int(0, $max)], range(1, self::LENGTH)));
    }
}
