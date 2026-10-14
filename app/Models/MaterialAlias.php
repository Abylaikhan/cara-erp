<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Алиас материала: the name a supplier writes in its invoice → the warehouse material (legacy material_aliases). Legacy links
 * it to the material by name; receipts resolve it later (resolveAlias, ERP-046).
 *
 * @property int $id
 * @property string $material_name
 * @property string $alias_name
 * @property string|null $category
 * @property int|null $supplier_id
 * @property string $coeff
 * @property string|null $comment
 */
class MaterialAlias extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['material_name', 'alias_name', 'category', 'supplier_id', 'coeff', 'comment'];

    protected function casts(): array
    {
        return ['coeff' => 'decimal:3'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
