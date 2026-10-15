<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectCalc;
use App\Models\ProjectComment;
use App\Models\ProjectFile;
use App\Models\SalesChannel;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\Audit;
use App\Support\ChannelScope;
use App\Support\Erp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M10 «Проекты» — legacy prjCreate/prjSaveHead/prjExecAdd/prjExecRemove/prjCalcSave/prjCalcSend/prjClientDecide/
 * prjUploadFiles/prjDeleteFile/prjAddComment/prjDeleteProject (L31144–31720). The server decides who may do what
 * (ProjectPolicy), counts the totals of a calculation and keeps the files; every change goes to the audit trail.
 */
class ProjectService
{
    public const STALE = 'Проект изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Проект не найден — обновите страницу.';

    public const FORBIDDEN = 'Недостаточно прав для этого действия.';

    public const FILE_NOT_FOUND = 'Файл не найден — обновите страницу.';

    public const NO_ITEMS = 'Добавьте хотя бы одну позицию';

    public const NOT_SENT = 'Расчёт уже не ожидает решения — обновите страницу.';

    /** Legacy prjUploadFiles skips files larger than 25 MB. */
    public const MAX_FILE_BYTES = 25 * 1048576;

    public const DISK = 'local';

    /**
     * Projects the user may see: not deleted, of the channels the user may see (legacy loadProjects).
     *
     * @return Builder<Project>
     */
    public static function visible(User $user): Builder
    {
        return ChannelScope::apply(Project::query()->active(), $user);
    }

    /** Legacy prjFmt: «₸» and whole tenge with grouped thousands (ru-RU). */
    public static function money(string $amount): string
    {
        $whole = bcadd($amount, bccomp($amount, '0', 2) < 0 ? '-0.5' : '0.5', 0);
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $digits = ltrim($whole, '-');

        $groups = array_reverse(array_map('strrev', str_split(strrev($digits), 3)));

        return '₸'.$sign.implode("\u{00A0}", $groups);
    }

    /** @param array{title: string, channel?: string|null, customer_name?: string|null, description?: string|null, due_date?: string|null} $data */
    public function create(array $data, User $by): Project
    {
        Gate::forUser($by)->authorize('create', Project::class);

        return DB::transaction(function () use ($data, $by) {
            $client = ProjectPolicy::isClient($by);
            $channel = $client ? Erp::channelOf($by) : ($data['channel'] ?? null);
            $known = SalesChannel::query()->where('code', $channel)->value('name');
            if ($known === null || ! ChannelScope::allows($by, $channel)) {
                throw ValidationException::withMessages(['channel' => 'Выберите заказчика из списка.']);
            }
            $project = new Project([
                'title' => $data['title'],
                'channel' => $channel,
                'status' => 'request',
                // Legacy: the customer's own request carries the channel name; staff may name the client.
                'customer_name' => $client ? $known : ($data['customer_name'] ?? null) ?? $known,
                'description' => $data['description'] ?? '',
                'due_date' => $data['due_date'] ?? null,
            ]);
            $project->forceFill(['created_by_id' => $by->id, 'created_by_login' => $by->login, 'created_by_name' => $by->name, 'executors' => []])->save();
            $project->forceFill(['num' => 'ПР-'.str_pad((string) $project->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            Audit::record('project.created', 'project', $project->id, $project->only(['num', 'title', 'channel', 'customer_name', 'due_date']), $by);

            return $project;
        });
    }

    /**
     * Legacy prjSaveHead. The customer changes title, description and date of a request; staff also the status and the order
     * number — an order number makes the project «Стал заказом» unless it is (being) closed. An empty title keeps the old one.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateHead(int $id, array $data, int $lockVersion, User $by): Project
    {
        return DB::transaction(function () use ($id, $data, $lockVersion, $by) {
            $project = $this->locked($id, $by, 'updateHead');
            if ($project->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $changes = [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $project->title,
                'description' => $data['description'] ?? '',
                'due_date' => $data['due_date'] ?? null,
            ];
            if (! ProjectPolicy::isClient($by)) {
                $changes['status'] = $data['status'];
                $changes['order_num'] = $data['order_num'] ?? null;
                if ($changes['order_num'] !== null && $project->status !== 'closed' && $changes['status'] !== 'closed') {
                    $changes['status'] = 'order';
                }
            }
            $this->apply($project, $changes, 'project.updated', $by);
            $project->touch();

            return $project;
        });
    }

    /** Legacy prjExecAdd: a staff user (a role without a channel) becomes an executor. */
    public function addExecutor(int $id, string $login, User $by): Project
    {
        return DB::transaction(function () use ($id, $login, $by) {
            $project = $this->locked($id, $by, 'manage');
            $user = User::query()->where('login', $login)->where('is_active', true)->first();
            if ($user === null || Erp::channelOf($user) !== null) {
                throw ValidationException::withMessages(['login' => 'Исполнитель не найден — обновите страницу.']);
            }
            if (! in_array($login, $project->executors, true)) {
                $this->saveExecutors($project, [...$project->executors, $login], $by);
            }

            return $project;
        });
    }

    /** Legacy prjExecRemove. */
    public function removeExecutor(int $id, string $login, User $by): Project
    {
        return DB::transaction(function () use ($id, $login, $by) {
            $project = $this->locked($id, $by, 'manage');
            if (in_array($login, $project->executors, true)) {
                $this->saveExecutors($project, array_values(array_diff($project->executors, [$login])), $by);
            }

            return $project;
        });
    }

    /**
     * Legacy prjCalcSave: lines with a name or a price are kept; the version being edited is updated while it has not been sent,
     * otherwise (or on «Новая версия») the next version is made. The project takes the total, the author becomes an executor,
     * a request becomes «В расчёте».
     *
     * @param  list<array{name?: string|null, attrs?: string|null, qty?: string|null, cost?: string|null, price?: string|null}>  $items
     */
    public function saveCalc(int $id, ?int $version, array $items, ?string $markup, bool $asNew, User $by): ProjectCalc
    {
        return DB::transaction(fn () => $this->storeCalc($this->locked($id, $by, 'manage'), $version, $items, $markup, $asNew, $by));
    }

    /**
     * Legacy prjCalcSend: the calculation is saved and sent; the customer sees it (prices only) and the project waits for a
     * decision. A message about it goes to the discussion.
     *
     * @param  list<array<string, string|null>>  $items
     */
    public function sendCalc(int $id, ?int $version, array $items, ?string $markup, User $by): ProjectCalc
    {
        return DB::transaction(function () use ($id, $version, $items, $markup, $by) {
            $project = $this->locked($id, $by, 'manage');
            $calc = $this->storeCalc($project, $version, $items, $markup, false, $by);
            $calc->forceFill(['sent_at' => now()])->save();
            $this->apply($project, ['status' => 'sent', 'total_amount' => $calc->total], 'project.calc_sent', $by, ['version' => $calc->version]);
            $this->comment($project, "📤 Отправлен расчёт v{$calc->version} на сумму ".self::money($calc->total).'.', false, $by);

            return $calc;
        });
    }

    /** Legacy prjClientDecide: «Принять расчёт» approves, «Отказаться» closes the project; both are written to the discussion. */
    public function decide(int $id, bool $accept, ?string $reason, User $by): Project
    {
        return DB::transaction(function () use ($id, $accept, $reason, $by) {
            $project = $this->locked($id, $by, 'decide');
            if ($project->status !== 'sent') {
                throw ValidationException::withMessages(['status' => self::NOT_SENT]);
            }
            $this->apply($project, ['status' => $accept ? 'approved' : 'closed'], $accept ? 'project.accepted' : 'project.declined', $by);
            $this->comment($project, $accept ? '✅ Расчёт принят заказчиком.' : '✖ Заказчик отказался.'.($reason ? ' Причина: '.$reason : ''), false, $by);

            return $project;
        });
    }

    /** Legacy prjAddComment: the customer's messages are never internal. */
    public function addComment(int $id, string $text, bool $internal, User $by): ProjectComment
    {
        return DB::transaction(function () use ($id, $text, $internal, $by) {
            $project = $this->locked($id, $by, 'view');
            $comment = $this->comment($project, $text, $internal && ! ProjectPolicy::isClient($by), $by);
            $project->touch();

            return $comment;
        });
    }

    /**
     * Legacy prjUploadFiles: the customer adds files to its own side only and never internal ones; at most 25 MB a file. The type
     * is taken from the content, not from the browser.
     */
    public function addFile(int $id, string $side, bool $internal, UploadedFile $file, User $by): ProjectFile
    {
        $client = ProjectPolicy::isClient($by);
        if ($client && $side !== 'customer') {
            throw ValidationException::withMessages(['side' => self::FORBIDDEN]);
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages(['file' => '«'.$file->getClientOriginalName().'» больше 25 МБ — пропущен']);
        }
        $name = Str::limit(trim(str_replace(['/', '\\', "\0"], '_', $file->getClientOriginalName())) ?: 'file', 250, '');
        $safe = substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name), -70);
        $path = null;
        try {
            return DB::transaction(function () use ($id, $side, $internal, $file, $by, $client, $name, $safe, &$path) {
                $project = $this->locked($id, $by, 'view');
                $path = $file->storeAs("projects/{$project->id}", Str::uuid()->toString().'_'.$safe, self::DISK);
                if ($path === false) {
                    throw ValidationException::withMessages(['file' => 'Загрузка файла «'.$name.'» не удалась.']);
                }
                $row = new ProjectFile(['project_id' => $project->id, 'side' => $side, 'internal' => $internal && ! $client,
                    'file_name' => $name, 'storage_path' => $path, 'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize()]);
                $row->forceFill(['uploaded_by_id' => $by->id, 'uploaded_by_login' => $by->login, 'uploaded_by_name' => $by->name])->save();
                $project->touch();
                Audit::record('project.file_added', 'project', $project->id, ['file' => $row->id, 'name' => $name, 'side' => $side, 'internal' => $row->internal], $by);

                return $row;
            });
        } catch (\Throwable $e) {
            if (is_string($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
            throw $e;
        }
    }

    /** A file the user may see (an internal one is not visible to the customer). */
    public function file(int $fileId, User $by): ProjectFile
    {
        $file = ProjectFile::query()->find($fileId);
        $project = $file ? self::visible($by)->find($file->project_id) : null;
        if ($file === null || $project === null || ! Gate::forUser($by)->allows('view', $project) || ($file->internal && ProjectPolicy::isClient($by))) {
            throw ValidationException::withMessages(['file' => self::FILE_NOT_FOUND]);
        }

        return $file;
    }

    /** Legacy prjDeleteFile: the director, the author of the file or any staff user. */
    public function deleteFile(int $fileId, User $by): void
    {
        $path = DB::transaction(function () use ($fileId, $by) {
            $file = $this->file($fileId, $by);
            $project = $this->locked($file->project_id, $by, 'view');
            if (! (ProjectPolicy::isAdmin($by) || $file->uploaded_by_login === $by->login || ! ProjectPolicy::isClient($by))) {
                throw ValidationException::withMessages(['file' => self::FORBIDDEN]);
            }
            $file->delete();
            $project->touch();
            Audit::record('project.file_deleted', 'project', $project->id, ['file' => $file->id, 'name' => $file->file_name], $by);

            return $file->storage_path;
        });
        Storage::disk(self::DISK)->delete($path);
    }

    /** Legacy prjDeleteProject deleted everything; here the project is deactivated and kept with its files for the history. */
    public function delete(int $id, int $lockVersion, User $by): void
    {
        DB::transaction(function () use ($id, $lockVersion, $by) {
            $project = $this->locked($id, $by, 'delete');
            if ($project->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $project->forceFill(['active' => false, 'deactivated_at' => now()]);
            $project->lock_version++;
            $project->save();
            Audit::record('project.deactivated', 'project', $project->id, ['num' => $project->num, 'title' => $project->title], $by);
        });
    }

    /**
     * @param  list<array<string, string|null>>  $items
     */
    private function storeCalc(Project $project, ?int $version, array $items, ?string $markup, bool $asNew, User $by): ProjectCalc
    {
        $lines = [];
        foreach ($items as $i) {
            $line = ['name' => trim((string) ($i['name'] ?? '')), 'attrs' => trim((string) ($i['attrs'] ?? '')),
                'qty' => self::decimal($i['qty'] ?? null, 3), 'cost' => self::decimal($i['cost'] ?? null, 2), 'price' => self::decimal($i['price'] ?? null, 2)];
            if ($line['name'] !== '' || bccomp($line['price'], '0', 2) > 0) {
                $lines[] = $line;
            }
        }
        if (! $lines) {
            throw ValidationException::withMessages(['items' => self::NO_ITEMS]);
        }
        $total = self::total($lines);
        $current = $version === null ? null : $project->calcs()->where('version', $version)->lockForUpdate()->first();
        if ($current !== null && ! $asNew && $current->sent_at === null) {
            $current->fill(['items' => $lines, 'total' => $total, 'markup_pct' => $markup])->save();
            $calc = $current;
            Audit::record('project.calc_saved', 'project', $project->id, ['version' => $calc->version, 'total' => $total, 'lines' => count($lines)], $by);
        } else {
            $next = (int) $project->calcs()->max('version') + 1;
            $calc = new ProjectCalc(['project_id' => $project->id, 'version' => $next, 'items' => $lines, 'total' => $total, 'markup_pct' => $markup]);
            $calc->forceFill(['created_by_id' => $by->id, 'created_by_login' => $by->login, 'created_by_name' => $by->name])->save();
            Audit::record('project.calc_created', 'project', $project->id, ['version' => $next, 'total' => $total, 'lines' => count($lines)], $by);
        }
        $changes = ['total_amount' => $total];
        if ($project->status === 'request') {
            $changes['status'] = 'calc';
        }
        $this->apply($project, $changes, 'project.updated', $by);
        if (! in_array($by->login, $project->executors, true)) {
            $this->saveExecutors($project, [...$project->executors, $by->login], $by);
        }
        $project->touch();

        return $calc;
    }

    /** @param list<array{qty: string, price: string}> $lines */
    public static function total(array $lines): string
    {
        $sum = '0';
        foreach ($lines as $l) {
            $sum = bcadd($sum, bcmul($l['qty'], $l['price'], 5), 5);
        }

        return bcadd($sum, '0.005', 2); // non-negative: half up to the tiyn
    }

    /** Legacy +value||0: an empty field is zero. The request has already checked the format. */
    private static function decimal(?string $value, int $scale): string
    {
        $value = trim((string) $value);

        return bcadd($value === '' ? '0' : $value, '0', $scale);
    }

    /** @param list<string> $logins */
    private function saveExecutors(Project $project, array $logins, User $by): void
    {
        $names = User::query()->whereIn('login', $logins)->pluck('name', 'login');
        $before = $project->executors;
        $project->forceFill(['executors' => $logins, 'responsible_name' => implode(', ', array_map(fn ($l) => $names[$l] ?? $l, $logins)) ?: null])->save();
        Audit::record('project.executors_changed', 'project', $project->id, ['from' => $before, 'to' => $logins], $by);
    }

    private function comment(Project $project, string $text, bool $internal, User $by): ProjectComment
    {
        $comment = new ProjectComment(['project_id' => $project->id, 'side' => ProjectPolicy::isClient($by) ? 'client' : 'staff', 'internal' => $internal, 'text' => $text]);
        $comment->forceFill(['author_id' => $by->id, 'author_login' => $by->login, 'author_name' => $by->name])->save();

        return $comment;
    }

    /**
     * Head fields (and the status/total that calculations and decisions change) raise the record version.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $extra
     */
    private function apply(Project $project, array $changes, string $action, User $by, array $extra = []): void
    {
        $before = $project->only(array_keys($changes));
        $project->fill(array_intersect_key($changes, array_flip($project->getFillable())));
        if (array_key_exists('total_amount', $changes)) {
            $project->forceFill(['total_amount' => $changes['total_amount']]);
        }
        $diff = collect($project->getDirty())->except(['executors', 'responsible_name'])
            ->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $project->{$key}])->all();
        if ($diff) {
            $project->lock_version++;
            $project->save();
            Audit::record($action, 'project', $project->id, $diff + $extra, $by);
        }
    }

    private function locked(int $id, User $by, string $ability): Project
    {
        $project = self::visible($by)->whereKey($id)->lockForUpdate()->first();
        if ($project === null || ! Gate::forUser($by)->allows('view', $project)) {
            throw ValidationException::withMessages(['project' => self::NOT_FOUND]);
        }
        if (! Gate::forUser($by)->allows($ability, $project)) {
            throw ValidationException::withMessages(['project' => self::FORBIDDEN]);
        }

        return $project;
    }
}
