<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Входящая ЭСФ (M05, legacy incoming_invoices). Imported from the XML of the IS ESF; lines are kept with it atomically.
 *
 * @property int $id
 * @property string|null $num
 * @property string|null $reg_num
 * @property Carbon $date
 * @property string $status
 * @property string $invoice_type
 * @property int|null $supplier_id
 * @property string $supplier_name
 * @property string|null $supplier_bin
 * @property string $total_amount
 * @property string $total_nds
 * @property string $total_with_nds
 * @property int $lock_version
 */
class IncomingInvoice extends Model
{
    use HasPreciseTimestamps;

    /** Legacy setEsfType / esf-type filter. */
    public const TYPES = ['general', 'service', 'goods'];

    protected $fillable = ['num', 'reg_num', 'date', 'status', 'invoice_type', 'supplier_id', 'supplier_name', 'supplier_bin', 'buyer_name', 'buyer_bin',
        'currency', 'total_amount', 'total_nds', 'total_with_nds', 'source_file'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'total_nds' => 'decimal:2',
            'total_with_nds' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return HasMany<IncomingInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(IncomingInvoiceItem::class, 'invoice_id')->orderBy('line_no');
    }

    /** @return HasMany<EsfPaymentLink, $this> */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(EsfPaymentLink::class, 'invoice_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
