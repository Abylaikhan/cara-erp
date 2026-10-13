<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\SupplierMerge;
use App\Models\User;
use App\Support\Audit;
use App\Support\LegacyCsv;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Supplier operations (M06): transactions, row locks, lock_version against lost updates, audit trail. */
class SupplierService
{
    public const STALE = 'Поставщик изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Поставщик не найден или уже удалён — обновите страницу.';

    private const FIELDS = ['name', 'category', 'status', 'contact', 'phone', 'email', 'bin', 'address', 'lead_time_days', 'turnover', 'defect_pct', 'comment', 'expense_category_id', 'expense_source'];

    /** Fields a duplicate or a CSV row may fill when the kept supplier has them empty (legacy mergeSupplierGroup, importSuppliersCSV). */
    private const FILLABLE_FROM_OTHERS = ['bin', 'category', 'contact', 'phone', 'email'];

    /** CSV columns of legacy importSuppliersCSV (L17754), including 1С УНФ names. */
    private const CSV_COLUMNS = [
        'name' => ['название', 'наименование', 'контрагент', 'поставщик', 'организация'],
        'bin' => ['бин/иин', 'бин', 'иин', 'bin'],
        'category' => ['категория', 'группа', 'вид', 'тип'],
        'contact' => ['контактное лицо', 'контакт', 'ответственный', 'фио'],
        'phone' => ['телефон', 'тел', 'phone', 'контактный телефон'],
        'email' => ['email', 'e-mail', 'почта'],
        'turnover' => ['оборот за год', 'оборот', 'сумма', 'оборот, год', 'годовой оборот'],
        'leadtime' => ['срок поставки (дни)', 'срок поставки', 'срок', 'дни'],
        'status' => ['статус', 'состояние'],
    ];

    private const CSV_LIMITS = ['name' => 255, 'bin' => 32, 'category' => 100, 'contact' => 255, 'phone' => 64, 'email' => 255];

    private const CSV_LABELS = ['name' => 'Название', 'bin' => 'БИН', 'category' => 'Категория', 'contact' => 'Контактное лицо', 'phone' => 'Телефон', 'email' => 'Email'];

    /**
     * Legacy saveSupplierCard refuses a new card when findSupplierForName finds one (BIN, the same or a contained name).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $categoryId, ?int $articleId, User $by): Supplier
    {
        return DB::transaction(function () use ($data, $categoryId, $articleId, $by) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('suppliers')]); // two identical cards cannot pass the check together
            $dup = Supplier::findForName(Supplier::query()->active()->orderBy('name')->orderBy('id')->get(), $data['name'], $data['bin'] ?? null);
            if ($dup !== null) {
                throw ValidationException::withMessages(['name' => "Поставщик «{$dup->name}» с таким БИН/названием уже есть в справочнике — откройте его карточку вместо создания новой."]);
            }
            $supplier = new Supplier($data);
            $supplier->forceFill([
                'expense_category_id' => $this->expenseItemId($categoryId, $articleId, null),
                'created_by_id' => $by->id, 'created_by_name' => $by->name,
                'updated_by_id' => $by->id, 'updated_by_name' => $by->name,
            ])->save();
            Audit::record('supplier.created', 'supplier', $supplier->id, $supplier->only(self::FIELDS), $by);

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, ?int $categoryId, ?int $articleId, int $lockVersion, User $by): Supplier
    {
        return DB::transaction(function () use ($id, $data, $categoryId, $articleId, $lockVersion, $by) {
            $supplier = $this->locked($id);
            if ($supplier->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            $before = $supplier->only(self::FIELDS);
            $supplier->fill($data);
            $supplier->expense_category_id = $this->expenseItemId($categoryId, $articleId, $supplier->getOriginal('expense_category_id'));
            $changes = collect($supplier->getDirty())->map(fn ($new, $key) => ['from' => $before[$key] ?? null, 'to' => $new])->all();
            if ($changes) {
                $supplier->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $supplier->lock_version++;
                $supplier->save();
                Audit::record('supplier.updated', 'supplier', $supplier->id, $changes, $by);
            }

            return $supplier;
        });
    }

    /** Legacy deleteSupplier removes the row; here it is deactivated so payments, ESF and purchases keep their supplier. */
    public function deactivate(int $id, User $by): Supplier
    {
        return DB::transaction(function () use ($id, $by) {
            $supplier = $this->locked($id);
            $supplier->forceFill(['active' => false, 'deactivated_at' => now(), 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $supplier->lock_version++;
            $supplier->save();
            Audit::record('supplier.deactivated', 'supplier', $supplier->id, ['name' => $supplier->name], $by);

            return $supplier;
        });
    }

    /**
     * Legacy «Объединить» (mergeSupplierGroup): empty fields of the kept supplier are filled from the others, their names go
     * to the comment, turnover is added up. The others are deactivated with a link to the kept one (legacy deletes them),
     * registered references are moved, and the counts before/after are kept (ТЗ M06, AC-58).
     *
     * @param  list<int>  $mergedIds  in the order of the group
     */
    public function merge(int $keptId, array $mergedIds, User $by): SupplierMerge
    {
        $mergedIds = array_values(array_unique(array_map('intval', $mergedIds)));
        if ($mergedIds === [] || in_array($keptId, $mergedIds, true)) {
            throw ValidationException::withMessages(['merged_ids' => 'Выберите основного поставщика и хотя бы одну другую запись.']);
        }

        return DB::transaction(function () use ($keptId, $mergedIds, $by) {
            $all = [$keptId, ...$mergedIds];
            $locked = Supplier::query()->whereIn('id', $all)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($locked->count() !== count($all) || $locked->contains(fn (Supplier $s) => ! $s->active)) {
                throw ValidationException::withMessages(['merged_ids' => 'Поставщик не найден или уже объединён — обновите страницу.']);
            }
            $kept = $locked->get($keptId);
            $others = collect($mergedIds)->map(fn (int $id) => $locked->get($id));
            $before = $this->referenceCounts($all);

            $filled = [];
            foreach ($others as $other) {
                foreach (self::FILLABLE_FROM_OTHERS as $field) {
                    if (blank($kept->{$field}) && filled($other->{$field})) {
                        $kept->{$field} = $other->{$field};
                        $filled[$field] = $other->id;
                    }
                }
            }
            $names = $others->pluck('name')->filter(fn (string $n) => $n !== $kept->name)->values();
            if ($names->isNotEmpty()) {
                $kept->comment = ($kept->comment ? $kept->comment.' | ' : '').'Объединено с: '.$names->implode(', ');
            }
            if ($others->contains(fn (Supplier $s) => $s->turnover !== null) || $kept->turnover !== null) {
                $kept->turnover = bcadd((string) ($kept->turnover ?? '0'), (string) $others->reduce(fn (string $sum, Supplier $s) => bcadd($sum, (string) ($s->turnover ?? '0'), 2), '0'), 2);
            }
            $kept->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
            $kept->lock_version++;
            $kept->save();

            foreach (config('erp.supplier_references', []) as $ref) {
                DB::table($ref['table'])->whereIn($ref['column'], $mergedIds)->update([$ref['column'] => $kept->id]);
            }
            foreach ($others as $other) {
                $other->forceFill(['active' => false, 'deactivated_at' => now(), 'merged_into_id' => $kept->id, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $other->lock_version++;
                $other->save();
            }
            $after = $this->referenceCounts($all);
            $merge = SupplierMerge::create([
                'supplier_id' => $kept->id, 'merged_ids' => $mergedIds, 'user_id' => $by->id,
                'filled_fields' => $filled, 'references_before' => $before, 'references_after' => $after,
            ]);
            Audit::record('supplier.merged', 'supplier', $kept->id, ['merged' => $mergedIds, 'names' => $others->pluck('name')->all(), 'filled' => $filled, 'before' => $before, 'after' => $after], $by);

            return $merge;
        });
    }

    /**
     * Legacy importSuppliersCSV: the file is read with the legacy rules and compared with the list — by BIN, otherwise by the
     * normalized name. New suppliers are added, empty fields of known ones are filled. The first call only reports the plan
     * for the legacy confirmation; the second call (apply) computes the plan again and applies it in one transaction.
     *
     * @return array{type: 'alert'|'confirm', text: string}
     */
    public function importCsv(string $text, bool $apply, User $by): array
    {
        $alert = fn (string $t) => ['type' => 'alert', 'text' => $t];
        if (! mb_check_encoding($text, 'UTF-8')) {
            return $alert('Файл не в кодировке UTF-8. Сохраните CSV в UTF-8 и повторите.');
        }
        $lines = LegacyCsv::lines($text);
        if (count($lines) < 2) {
            return $alert('Файл пуст или содержит только заголовок.');
        }
        $delimiter = LegacyCsv::delimiter($lines[0]);
        $header = array_map('mb_strtolower', LegacyCsv::parseLine($lines[0], $delimiter));
        $idx = array_map(fn (array $keys) => LegacyCsv::findColumn($header, $keys), self::CSV_COLUMNS);
        if ($idx['name'] === -1) {
            return $alert('Не найдена колонка с названием поставщика. Проверьте, что в файле есть колонка «Название» или «Контрагент».');
        }

        $rows = [];
        $skipped = 0;
        $problems = [];
        foreach (array_slice($lines, 1) as $i => $line) {
            $cells = LegacyCsv::parseLine($line, $delimiter);
            $get = fn (string $key) => $idx[$key] > -1 ? LegacyCsv::trim($cells[$idx[$key]] ?? '') : '';
            if ($get('name') === '') {
                $skipped++;

                continue;
            }
            $row = ['name' => $get('name'), 'turnover' => self::csvTurnover($get('turnover')), 'leadtime' => self::csvLeadTime($get('leadtime')), 'status' => self::csvStatus($get('status'))];
            foreach (array_keys(self::CSV_LIMITS) as $field) {
                $row[$field] = $get($field);
                if (mb_strlen($row[$field]) > self::CSV_LIMITS[$field]) {
                    $problems[] = 'строка '.($i + 2).': поле «'.self::CSV_LABELS[$field].'» длиннее '.self::CSV_LIMITS[$field].' символов';
                }
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            return $alert("Не найдено ни одной строки с названием поставщика. Пропущено строк: {$skipped}");
        }
        if ($problems) {
            return $alert('Импорт не выполнен: '.implode('; ', array_slice($problems, 0, 5)).'.');
        }

        return DB::transaction(function () use ($rows, $skipped, $apply, $by, $alert) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('suppliers')]);
            $existing = Supplier::query()->active()->orderBy('name')->orderBy('id')->when($apply, fn ($q) => $q->lockForUpdate())->get();
            [$create, $enrich] = $this->importPlan($rows, $existing);
            if ($create === [] && $enrich === []) {
                return $alert('Новых поставщиков не найдено — все '.count($rows).' записей из файла уже есть в справочнике.');
            }
            if (! $apply) {
                $text = 'В файле строк: '.count($rows)."\n"
                    .($create ? 'Будет добавлено новых поставщиков: '.count($create)."\n" : '')
                    .($enrich ? 'Будут дополнены данные у существующих: '.count($enrich)."\n" : '')
                    .($skipped ? "Пропущено строк без названия: {$skipped}\n" : '');

                return ['type' => 'confirm', 'text' => $text."\nПродолжить?"];
            }
            foreach ($create as $row) {
                $supplier = new Supplier([
                    'name' => $row['name'], 'category' => $row['category'] ?: null, 'status' => $row['status'], 'contact' => $row['contact'] ?: null,
                    'phone' => $row['phone'] ?: null, 'email' => $row['email'] ?: null, 'bin' => $row['bin'] ?: null, 'comment' => 'Импортирован из CSV',
                    'turnover' => $row['turnover'], 'lead_time_days' => $row['leadtime'],
                ]);
                $supplier->forceFill(['created_by_id' => $by->id, 'created_by_name' => $by->name, 'updated_by_id' => $by->id, 'updated_by_name' => $by->name])->save();
                Audit::record('supplier.created', 'supplier', $supplier->id, $supplier->only(self::FIELDS), $by);
            }
            foreach ($enrich as [$supplier, $patch]) {
                $before = $supplier->getOriginal();
                $supplier->forceFill(['updated_by_id' => $by->id, 'updated_by_name' => $by->name]);
                $supplier->lock_version++;
                $supplier->save();
                Audit::record('supplier.updated', 'supplier', $supplier->id, collect($patch)->map(fn ($v, $k) => ['from' => $before[$k] ?? null, 'to' => $v])->all(), $by);
            }
            Audit::record('supplier.imported', 'supplier', null, ['rows' => count($rows), 'created' => count($create), 'enriched' => count($enrich), 'skipped' => $skipped], $by);

            return $alert("Готово!\nДобавлено новых: ".count($create)."\nДополнено существующих: ".count($enrich)."\nПропущено (без названия): {$skipped}");
        });
    }

    /**
     * Legacy plan: a file row is skipped when the file already had its key (BIN or normalized name) or the BIN is known;
     * a row whose normalized name is known fills the empty fields of that supplier; any other row becomes a new supplier.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Supplier>  $existing
     * @return array{0: list<array<string, mixed>>, 1: list<array{0: Supplier, 1: array<string, string>}>}
     */
    private function importPlan(array $rows, Collection $existing): array
    {
        $byBin = [];
        $byName = [];
        foreach ($existing as $s) {
            if (filled($s->bin)) {
                $byBin[trim($s->bin)] = $s;
            }
            $byName[Supplier::normalizedName($s->name)] = $s;
        }
        $seen = [];
        $create = [];
        $enrich = [];
        foreach ($rows as $row) {
            $key = $row['bin'] !== '' ? $row['bin'] : Supplier::normalizedName($row['name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($row['bin'] !== '' && isset($byBin[$row['bin']])) {
                continue;
            }
            $known = $byName[Supplier::normalizedName($row['name'])] ?? null;
            if ($known !== null) {
                $patch = [];
                foreach (self::FILLABLE_FROM_OTHERS as $field) {
                    if ($row[$field] !== '' && blank($known->{$field})) {
                        $known->{$field} = $row[$field];
                        $patch[$field] = $row[$field];
                    }
                }
                if ($patch) {
                    $enrich[] = [$known, $patch];
                }

                continue;
            }
            $create[] = $row;
        }

        return [$create, $enrich];
    }

    /** Legacy: parseFloat of the digits with the first comma as the decimal point; 0 means «not set». */
    private static function csvTurnover(string $raw): ?string
    {
        $clean = preg_replace('/[^\d.,-]/u', '', $raw) ?? '';
        $pos = strpos($clean, ',');
        $clean = $pos === false ? $clean : substr_replace($clean, '.', $pos, 1);
        $value = preg_match('/^-?\d*\.?\d+|^-?\d+/', $clean, $m) ? (float) $m[0] : 0.0;

        return $value > 0 ? number_format($value, 2, '.', '') : null;
    }

    /** Legacy: parseFloat of the digits (a comma ends the number); stored as whole days. */
    private static function csvLeadTime(string $raw): ?int
    {
        $clean = preg_replace('/[^\d.,-]/u', '', $raw) ?? '';
        $value = preg_match('/^-?\d*\.?\d+|^-?\d+/', $clean, $m) ? (float) $m[0] : 0.0;

        return $value > 0 ? (int) round($value) : null;
    }

    /** Legacy mapStatus. */
    private static function csvStatus(string $raw): string
    {
        $s = mb_strtolower($raw);

        return match (true) {
            str_contains($s, 'пауз') => 'pause',
            str_contains($s, 'неакт') || str_contains($s, 'заблок') || str_contains($s, 'заверш') => 'blocked',
            default => 'active',
        };
    }

    /** @return array<string, array<int, int>> */
    private function referenceCounts(array $ids): array
    {
        $counts = [];
        foreach (config('erp.supplier_references', []) as $ref) {
            $rows = DB::table($ref['table'])->whereIn($ref['column'], $ids)
                ->selectRaw("{$ref['column']} as supplier_id, count(*) as n")->groupBy($ref['column'])->pluck('n', 'supplier_id');
            $counts["{$ref['table']}.{$ref['column']}"] = array_map('intval', array_replace(array_fill_keys($ids, 0), $rows->all()));
        }

        return $counts;
    }

    /**
     * The expense item of the card: the article, or the top-level category when no article is chosen. A value that is already
     * stored is kept even if it was deleted in «Статьи расходов» later, so saving the card never drops the link silently.
     */
    private function expenseItemId(?int $categoryId, ?int $articleId, ?int $current): ?int
    {
        $wanted = $articleId ?? $categoryId;
        if ($wanted === null || $wanted === $current) {
            return $wanted;
        }
        $category = $categoryId !== null ? ExpenseCategory::query()->active()->whereNull('parent_id')->find($categoryId) : null;
        if ($category === null) {
            throw ValidationException::withMessages(['expense_category_id' => 'Категория расхода не найдена — обновите страницу.']);
        }
        if ($articleId === null) {
            return $category->id;
        }
        $article = ExpenseCategory::query()->active()->where('parent_id', $category->id)->find($articleId);
        if ($article === null) {
            throw ValidationException::withMessages(['expense_article_id' => 'Статья расхода не относится к выбранной категории — обновите страницу.']);
        }

        return $article->id;
    }

    private function locked(int $id): Supplier
    {
        $supplier = Supplier::query()->whereKey($id)->lockForUpdate()->first();
        if ($supplier === null || ! $supplier->active) {
            throw ValidationException::withMessages(['supplier' => self::NOT_FOUND]);
        }

        return $supplier;
    }
}
