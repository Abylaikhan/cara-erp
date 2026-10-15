<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\ExpenseCategoryMerge;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Business operations of «Статьи расходов» (M14). Every change runs in one transaction with row locks;
 * concurrent edits are detected by lock_version instead of silently overwriting (ТЗ DATA-01).
 */
class ExpenseCategoryService
{
    public const STALE = 'Статья изменена другим пользователем — обновите страницу и повторите.';

    /** @param array{name: string, code: string, parent_id: int|null, is_fixed: bool, sort_order: int} $data */
    public function create(array $data, User $by): ExpenseCategory
    {
        return DB::transaction(function () use ($data, $by) {
            if ($data['parent_id'] !== null) {
                $this->lockParent($data['parent_id']);
            }
            $category = ExpenseCategory::create($data);
            Audit::record('expense_category.created', 'expense_category', $category->id, $data, $by);

            return $category;
        });
    }

    /** @param array{name: string, parent_id: int|null, is_fixed: bool, sort_order: int} $data */
    public function update(int $id, array $data, int $lockVersion, User $by): ExpenseCategory
    {
        return DB::transaction(function () use ($id, $data, $lockVersion, $by) {
            $category = $this->lockActive($id);
            if ($category->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => self::STALE]);
            }
            // Level never changes: a category stays a category, an article stays an article (legacy form).
            $data['parent_id'] = $category->isTopLevel() ? null : $data['parent_id'];
            if (! $category->isTopLevel()) {
                if ($data['parent_id'] === null) {
                    throw ValidationException::withMessages(['parent_id' => 'Выберите категорию первого уровня']);
                }
                $this->lockParent($data['parent_id']);
            }
            $before = $category->only(array_keys($data));
            $category->fill($data);
            $changes = collect($category->getDirty())->map(fn ($new, $key) => ['from' => $before[$key], 'to' => $new])->all();
            $category->lock_version++;
            $category->save();
            if ($changes) {
                Audit::record('expense_category.updated', 'expense_category', $category->id, $changes, $by);
            }

            return $category;
        });
    }

    public function deactivate(int $id, User $by): ExpenseCategory
    {
        return DB::transaction(function () use ($id, $by) {
            $category = $this->lockActive($id);
            // FOR UPDATE cannot be combined with an aggregate in PostgreSQL: lock the rows, then count them.
            $subs = ExpenseCategory::query()->where('parent_id', $category->id)->active()->lockForUpdate()->pluck('id')->count();
            if ($subs > 0) {
                throw ValidationException::withMessages(['category' => "Нельзя удалить — есть {$subs} подстатей"]);
            }
            $category->forceFill(['active' => false, 'deactivated_at' => now()])->lock_version++;
            $category->save();
            Audit::record('expense_category.deactivated', 'expense_category', $category->id, ['code' => $category->code], $by);

            return $category;
        });
    }

    /**
     * Moves every registered reference from one article to another and deactivates the source — all or nothing: the ids
     * (config erp.expense_category_references), the group code of the money operations of the article and the codes in the
     * lines of the advance reports. Legacy mergeExpCats changed only money_tx.category in a separate request (QA-062).
     */
    public function merge(int $fromId, int $toId, User $by): ExpenseCategoryMerge
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_id' => 'Выберите разные статьи']);
        }

        return DB::transaction(function () use ($fromId, $toId, $by) {
            // Lock both rows in id order so two opposite merges cannot deadlock.
            $locked = ExpenseCategory::query()->whereIn('id', [$fromId, $toId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($fromId);
            $to = $locked->get($toId);
            foreach (['from_id' => $from, 'to_id' => $to] as $field => $category) {
                if ($category === null || ! $category->active) {
                    throw ValidationException::withMessages([$field => 'Статья не найдена или уже удалена — обновите страницу.']);
                }
                if ($category->isTopLevel()) {
                    throw ValidationException::withMessages([$field => 'Объединять можно только статьи второго уровня']);
                }
            }
            $before = $this->referenceCounts([$from->id, $to->id]) + $this->codeReferenceCounts($from, $to);
            $fromGroup = $from->parent?->code;
            $toGroup = $to->parent?->code;
            // A money operation keeps the group of its article by code (legacy money_tx.category): an article moved to another
            // group takes its operations there too. Done before the ids move, while the operations still name the source.
            if ($fromGroup !== $toGroup && Schema::hasTable('money_transactions')) {
                DB::table('money_transactions')->where('expense_category_id', $from->id)->where('category', $fromGroup)->update(['category' => $toGroup]);
            }
            foreach ($this->references() as $ref) {
                DB::table($ref['table'])->where($ref['column'], $from->id)->update([$ref['column'] => $to->id]);
            }
            // Advance report lines name the article and its group by code (legacy lines[].subcategory / lines[].category).
            if (Schema::hasTable('advance_reports')) {
                DB::update(<<<'SQL'
                    UPDATE advance_reports SET lines = (
                        SELECT jsonb_agg(CASE WHEN x.l->>'subcategory' = ?
                            THEN x.l || jsonb_build_object('subcategory', ?::text) || CASE WHEN x.l->>'category' = ? THEN jsonb_build_object('category', ?::text) ELSE '{}'::jsonb END
                            ELSE x.l END ORDER BY x.ord)
                        FROM jsonb_array_elements(advance_reports.lines) WITH ORDINALITY AS x(l, ord))
                    WHERE EXISTS (SELECT 1 FROM jsonb_array_elements(advance_reports.lines) AS y(l) WHERE y.l->>'subcategory' = ?)
                    SQL, [$from->code, $to->code, $fromGroup, $toGroup, $from->code]);
            }
            $after = $this->referenceCounts([$from->id, $to->id]) + $this->codeReferenceCounts($from, $to);
            $from->forceFill(['active' => false, 'deactivated_at' => now(), 'merged_into_id' => $to->id])->lock_version++;
            $from->save();
            $merge = ExpenseCategoryMerge::create([
                'from_id' => $from->id, 'to_id' => $to->id, 'user_id' => $by->id,
                'references_before' => $before, 'references_after' => $after,
            ]);
            Audit::record('expense_category.merged', 'expense_category', $from->id,
                ['from' => $from->code, 'to' => $to->code, 'before' => $before, 'after' => $after], $by);

            return $merge;
        });
    }

    /**
     * The lines of the advance reports that name the two articles by code.
     *
     * @return array<string, array<int, int>>
     */
    private function codeReferenceCounts(ExpenseCategory $from, ExpenseCategory $to): array
    {
        if (! Schema::hasTable('advance_reports')) {
            return [];
        }
        $rows = DB::table('advance_reports as a')->crossJoin(DB::raw('jsonb_array_elements(a.lines) AS l'))
            ->whereRaw("l->>'subcategory' IN (?, ?)", [$from->code, $to->code])->selectRaw("l->>'subcategory' AS code, count(*) AS n")->groupByRaw('1')->pluck('n', 'code');

        return ['advance_reports.lines.subcategory' => [$from->id => (int) ($rows[$from->code] ?? 0), $to->id => (int) ($rows[$to->code] ?? 0)]];
    }

    /** @return list<array{table: string, column: string, label?: string}> */
    public function references(): array
    {
        return config('erp.expense_category_references', []);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, array<int, int>>
     */
    private function referenceCounts(array $ids): array
    {
        $counts = [];
        foreach ($this->references() as $ref) {
            $rows = DB::table($ref['table'])->whereIn($ref['column'], $ids)
                ->selectRaw("{$ref['column']} as category_id, count(*) as n")->groupBy($ref['column'])->pluck('n', 'category_id');
            $counts["{$ref['table']}.{$ref['column']}"] = array_map('intval', array_replace(array_fill_keys($ids, 0), $rows->all()));
        }

        return $counts;
    }

    private function lockActive(int $id): ExpenseCategory
    {
        $category = ExpenseCategory::query()->whereKey($id)->lockForUpdate()->first();
        if ($category === null || ! $category->active) {
            throw ValidationException::withMessages(['category' => 'Статья не найдена или уже удалена — обновите страницу.']);
        }

        return $category;
    }

    private function lockParent(int $parentId): void
    {
        $parent = ExpenseCategory::query()->whereKey($parentId)->lockForUpdate()->first();
        if ($parent === null || ! $parent->active || ! $parent->isTopLevel()) {
            throw ValidationException::withMessages(['parent_id' => 'Выберите категорию первого уровня']);
        }
    }
}
