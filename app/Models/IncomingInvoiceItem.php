<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка ЭСФ (legacy incoming_invoice_items).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $line_no
 * @property string $description
 * @property string $qty
 * @property string $unit_price
 * @property string $amount
 * @property string $nds_rate
 * @property string $nds_amount
 * @property string $total
 * @property string|null $warehouse_code
 */
class IncomingInvoiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'line_no', 'description', 'qty', 'unit_price', 'amount', 'nds_rate', 'nds_amount', 'total', 'warehouse_code'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'nds_rate' => 'decimal:2', 'nds_amount' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
