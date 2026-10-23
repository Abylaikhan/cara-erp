<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Связь ЭСФ с оплатой или авансовым отчётом (legacy esf_payment_links): how much of the invoice that payment covers.
 * The source is named by its legacy id; links are saved in «Сверка с оплатами» (EsfLinkService, D-10, ADR-0048).
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
