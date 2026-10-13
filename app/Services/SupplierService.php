<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Supplier operations (M06): transactions, row locks, lock_version against lost updates, audit trail. */
class SupplierService
{
    public const STALE = 'Поставщик изменён другим пользователем — обновите страницу и повторите.';

    public const NOT_FOUND = 'Поставщик не найден или уже удалён — обновите страницу.';

    private const FIELDS = ['name', 'category', 'status', 'contact', 'phone', 'email', 'bin', 'address', 'lead_time_days', 'turnover', 'defect_pct', 'comment', 'expense_category_id', 'expense_source'];

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
