<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Связь ЭСФ с оплатой или авансовым отчётом (legacy esf_payment_links): how much of the invoice that payment covers.
 * Read-only until «Деньги» are migrated: the source is the legacy id (ERP-048, decision D-10).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $source_type
 * @property string|null $source_legacy_id
 * @property string $amount
 */
class EsfPaymentLink extends Model
{
    use HasPreciseTimestamps;

    public const UPDATED_AT = null;

    protected $fillable = ['invoice_id', 'source_type', 'source_legacy_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
