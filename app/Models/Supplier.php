<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Поставщик (M06, legacy table suppliers / suppliersData). Deleting deactivates the row: payments, ESF and purchases keep
 * pointing to it once those modules are migrated.
 *
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string $status
 * @property string|null $contact
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $bin
 * @property string|null $inn
 * @property string|null $address
 * @property int|null $lead_time_days
 * @property string|null $turnover
 * @property string|null $defect_pct
 * @property string|null $comment
 * @property int|null $expense_category_id
 * @property string $expense_source
 * @property bool $active
 * @property int $lock_version
 */
class Supplier extends Model
{
    use HasPreciseTimestamps;

    /** Legacy supStatusLabel / sup-card-status. */
    public const STATUSES = ['active' => 'Активен', 'pause' => 'На паузе', 'blocked' => 'Заблокирован'];

    public const EXPENSE_SOURCES = ['payment', 'esf'];

    /** Latin letters that look like Cyrillic ones (legacy LATIN_CYRILLIC_HOMOGLYPHS, L17891). */
    private const HOMOGLYPHS = ['a' => 'а', 'c' => 'с', 'e' => 'е', 'k' => 'к', 'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у', 'r' => 'р'];

    /** JS `\b` without the u flag: a boundary between [A-Za-z0-9_] and anything else. */
    private const JS_BOUNDARY = '(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])(?=[A-Za-z0-9_]))';

    protected $fillable = ['name', 'category', 'status', 'contact', 'phone', 'email', 'bin', 'address', 'lead_time_days', 'turnover', 'defect_pct', 'comment', 'expense_category_id', 'expense_source'];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'turnover' => 'decimal:2',
            'defect_pct' => 'decimal:2',
            'active' => 'boolean',
            'lock_version' => 'integer',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Legacy normSupNameMerge (L17896): the key under which two spellings count as the same supplier. Reproduced with its
     * quirks, so «already exists» answers stay the same: legal forms are matched with JS `\b` (which never fires next to
     * Cyrillic, and the Latin forms are already turned into Cyrillic look-alikes), and letters outside а–я/ё (Kazakh ә, қ, ң…)
     * become spaces.
     */
    public static function normalizedName(?string $name): string
    {
        $s = mb_strtolower(preg_replace('/\([^)]*\)/u', '', (string) $name) ?? '');
        $s = strtr($s, self::HOMOGLYPHS);
        $s = preg_replace('/["\'«»“”]/u', '', $s) ?? $s;
        $s = preg_replace('/'.self::JS_BOUNDARY.'(тоо|ип|ао|пк|чп|ооо|zhk|llp|ltd|kz)'.self::JS_BOUNDARY.'/iu', '', $s) ?? $s;
        $s = preg_replace('/[^a-zа-яё0-9]+/u', ' ', $s) ?? $s;

        return preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
    }

    /**
     * Legacy findSupplierForName (L17910): by BIN exactly, then by the normalized name, then by one name containing the other.
     *
     * @param  Collection<int, Supplier>  $suppliers  in the order of the list (by name)
     */
    public static function findForName(Collection $suppliers, ?string $name, ?string $bin): ?self
    {
        $bin = trim((string) $bin);
        if ($bin !== '') {
            $byBin = $suppliers->first(fn (self $s) => $s->bin !== null && trim($s->bin) === $bin);
            if ($byBin !== null) {
                return $byBin;
            }
        }
        $n = self::normalizedName($name);
        if ($n === '') {
            return null;
        }

        return $suppliers->first(fn (self $s) => self::normalizedName($s->name) === $n)
            ?? $suppliers->first(function (self $s) use ($n) {
                $sn = self::normalizedName($s->name);

                return $sn !== '' && (str_contains($sn, $n) || str_contains($n, $sn));
            });
    }
}
