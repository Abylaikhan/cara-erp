<?php

namespace App\Console\Commands;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ProjectFile;
use App\Models\User;
use App\Support\Erp;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the application shows, as checksums (ERP-074, AC-77): every active user opens every page (allowed → its Inertia
 * props, forbidden → the refusal) and every project file the CRM gives them; a guest opens the login and a page. One line
 * per user and target: HTTP status and SHA-256 of the props, of the redirect or of the file bytes — no data itself. The
 * clock is frozen at --at, so «today» and the current month are those of the moment recorded. Lists are compared as sets:
 * the order of rows a query does not fully sort is the planner's and may change after a restore (other statistics).
 *
 * make db-backup records it next to the copy; make db-restore-check starts the application on the restored copy and
 * compares: the same answers mean the restored database and files keep the links, the sums and the access.
 * Requests run inside this process with the session and the cache in memory: nothing is written to the database.
 */
class ViewDigest extends Command
{
    protected $signature = 'app:view-digest
        {--at= : Moment the pages are computed at, ISO 8601 (default: now); the clock is frozen there}
        {--out=- : Write the digest to this file (- = standard output)}
        {--compare= : Compare with a digest written earlier (- = standard input) instead of writing one}';

    protected $description = 'Checksums of what every user sees on every page and project file (backup → restore check)';

    private const MAX_USERS = 200;

    private string $version = '';

    public function handle(): int
    {
        // Answers come from the database only: sessions, cache and queue stay in memory.
        config(['session.driver' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync']);
        $at = CarbonImmutable::parse($this->option('at') ?: 'now')->utc();
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        Date::setTestNow($at);
        try {
            if (($compare = $this->option('compare')) !== null) {
                return $this->compare($this->readLines($compare));
            }
            $lines = $this->digest();
            $this->writeLines((string) $this->option('out'), $lines);
            $this->summary($lines, 'recorded');

            return self::SUCCESS;
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($previous);
            Date::setTestNow($previous);
        }
    }

    /** @param array<string, string> $expected */
    private function compare(array $expected): int
    {
        $problems = $this->migrationProblems();
        $actual = $problems ? [] : $this->digest();
        foreach ($problems ? [] : array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
            $was = isset($expected[$key]) ? explode("\t", $expected[$key]) : null;
            $now = isset($actual[$key]) ? explode("\t", $actual[$key]) : null;
            if ($was === $now) {
                continue;
            }
            $problems[] = $key.': '.match (true) {
                $was === null => 'not in the copy',
                $now === null => 'missing after restore',
                $was[0] !== $now[0] => "HTTP {$was[0]} → {$now[0]}",
                default => 'content differs',
            };
        }
        $this->summary($actual, 'compared');
        if ($problems) {
            foreach (array_slice($problems, 0, 30) as $p) {
                $this->stderr("  {$p}");
            }
            $this->stderr(count($problems).' difference(s) between the copy and the restored application');

            return self::FAILURE;
        }
        $this->stderr('The restored application shows the same: '.count($expected).' answers match');

        return self::SUCCESS;
    }

    /**
     * «user<TAB>target» → «status<TAB>sha256»; the user is the id or «guest», the target a path or «file:<id>».
     *
     * @return array<string, string>
     */
    private function digest(): array
    {
        $this->version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
        $lines = [];
        $add = function (string $user, string $target, Response $response, ?string $body = null) use (&$lines) {
            $status = $response->getStatusCode();
            $digest = match (true) {
                $body !== null => hash('sha256', $body),
                $response->isRedirection() => hash('sha256', (string) $response->headers->get('Location')),
                $status === 200 => hash('sha256', $this->canonicalProps((string) $response->getContent())),
                default => '-',
            };
            $lines["{$user}\t{$target}"] = "{$status}\t{$digest}";
        };

        foreach (['/login', '/dashboard'] as $path) {
            $add('guest', $path, $this->get(null, $path));
        }
        $users = User::query()->where('is_active', true)->orderBy('id')->limit(self::MAX_USERS)->get();
        $files = ProjectFile::query()->orderBy('id')->pluck('id');
        $hashed = [];
        foreach ($users as $user) {
            $add((string) $user->id, '/', $this->get($user, '/'));
            foreach (array_keys(Erp::pages()) as $page) {
                $add((string) $user->id, '/'.$page, $this->get($user, '/'.$page));
            }
            if (! Erp::canAccess($user, 'crm')) {
                continue;
            }
            foreach ($files as $id) {
                $response = $this->get($user, "/crm/projects/files/{$id}", inertia: false);
                // The bytes of a file are read once; for the other users only whether they get it.
                $body = $response->isOk() && ! isset($hashed[$id]) ? $this->body($response) : null;
                $hashed[$id] = $hashed[$id] ?? ($body !== null);
                $add((string) $user->id, "file:{$id}", $response, $body ?? ($response->isOk() ? 'served' : null));
            }
        }
        if ($users->count() === self::MAX_USERS) {
            $this->stderr('Only the first '.self::MAX_USERS.' active users are checked');
        }
        ksort($lines);

        return $lines;
    }

    private function get(?User $user, string $uri, bool $inertia = true): Response
    {
        $request = Request::create($uri, 'GET', server: $inertia
            ? ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => $this->version, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']
            : ['HTTP_ACCEPT' => '*/*']);
        $auth = app('auth');
        $auth->forgetGuards();
        if ($user !== null) {
            $auth->guard('web')->setUser($user);
        }
        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** The page component and its props in canonical form: object keys sorted, list elements sorted. */
    private function canonicalProps(string $json): string
    {
        try {
            $page = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $json; // not an Inertia page: its bytes as they are
        }
        if (! is_array($page)) {
            return $json;
        }
        $sort = function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);

                return $v;
            }
            $keyed = array_map(fn (mixed $e) => [json_encode($e, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $e], $v);
            usort($keyed, fn (array $a, array $b) => strcmp($a[0], $b[0]));

            return array_column($keyed, 1);
        };

        return json_encode($sort(['component' => $page['component'] ?? null, 'props' => $page['props'] ?? null]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The restored database must have exactly the migrations of this code, otherwise the pages are not comparable. */
    private function migrationProblems(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['migrations: the restored database has no migrations table'];
        }
        $ran = $migrator->getRepository()->getRan();
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $problems = [];
        if ($pending = array_diff($files, $ran)) {
            $problems[] = 'migrations: '.count($pending).' of this code not applied in the copy (the copy is older than the code)';
        }
        if ($unknown = array_diff($ran, $files)) {
            $problems[] = 'migrations: '.count($unknown).' applied in the copy are not in this code (the code is older than the copy)';
        }

        return $problems;
    }

    /** @return array<string, string> */
    private function readLines(string $source): array
    {
        $text = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if ($text === false) {
            throw new RuntimeException("Cannot read the digest {$source}");
        }
        $lines = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 4) {
                throw new RuntimeException('Not a view digest: every line is user, target, status, checksum');
            }
            $lines["{$parts[0]}\t{$parts[1]}"] = "{$parts[2]}\t{$parts[3]}";
        }

        return $lines;
    }

    /** @param array<string, string> $lines */
    private function writeLines(string $target, array $lines): void
    {
        $text = implode('', array_map(fn (string $k, string $v) => "{$k}\t{$v}\n", array_keys($lines), $lines));
        if ($target === '-') {
            $this->getOutput()->write($text, false, OutputInterface::OUTPUT_RAW);

            return;
        }
        file_put_contents($target, $text);
        chmod($target, 0600);
    }

    /** @param array<string, string> $lines */
    private function summary(array $lines, string $verb): void
    {
        $users = count(array_unique(array_map(fn (string $k) => strstr($k, "\t", true), array_keys($lines))));
        $files = count(array_filter(array_keys($lines), fn (string $k) => str_contains($k, "\tfile:")));
        $statuses = array_count_values(array_map(fn (string $v) => strstr($v, "\t", true), $lines));
        ksort($statuses);
        $this->stderr(sprintf('%s %d answers: %d users (with the guest), %d file answers; HTTP %s', ucfirst($verb), count($lines), $users, $files,
            implode(', ', array_map(fn ($s, $n) => "{$s}×{$n}", array_keys($statuses), $statuses))));
    }

    /** Messages go to standard error: standard output carries the digest itself. */
    private function stderr(string $message): void
    {
        $this->getOutput()->getErrorStyle()->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
