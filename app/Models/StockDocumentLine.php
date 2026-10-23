<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a stock document: material, quantity (to 0.001), its unit at the time, price and amount (to the tiyn).
 *
 * @property int $id
 * @property int $document_id
 * @property int $line_no
 * @property int $material_id
 * @property string $qty
 * @property string $unit
 * @property string $price
 * @property string $amount
 */
class StockDocumentLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_id', 'line_no', 'material_id', 'qty', 'unit', 'price', 'amount'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'price' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
