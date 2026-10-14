<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Заявка на материал (M04, legacy material_requests): what a workshop or the storekeeper asks to buy, and how far the purchase
 * has gone — «Заявлено» → «Взято в закупку» → «Пришло на склад».
 *
 * @property int $id
 * @property string $material_name
 * @property string $qty
 * @property string|null $unit
 * @property string|null $order_num
 * @property string|null $item_name
 * @property string|null $workshop
 * @property string|null $requested_by
 * @property string|null $purchase_note
 * @property string $status
 * @property int $lock_version
 */
class MaterialRequest extends Model
{
    use HasPreciseTimestamps;

    /** Legacy MREQ_STATUS_LABELS; each status has one next step (updateMaterialRequestStatus). */
    public const NEXT = ['requested' => 'in_purchase', 'in_purchase' => 'received'];

    protected $fillable = ['material_name', 'qty', 'unit', 'order_num', 'item_name', 'workshop', 'requested_by', 'purchase_note', 'status'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'lock_version' => 'integer'];
    }
}
