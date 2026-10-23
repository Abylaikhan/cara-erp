<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Складской документ (legacy whDocuments, «📥 Приходная накладная»): a draft does not move the stock; posting it does, once
 * (ADR-0049).
 *
 * @property int $id
 * @property string $doc_type
 * @property string $num
 * @property Carbon $doc_date
 * @property string $status
 * @property string|null $supplier_name
 * @property int|null $supplier_id
 * @property string|null $esf_ref
 * @property string|null $note
 * @property string|null $created_by_name
 * @property Carbon|null $posted_at
 * @property int $lock_version
 */
class StockDocument extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['doc_type', 'num', 'doc_date', 'status', 'supplier_name', 'supplier_id', 'esf_ref', 'note', 'created_by_id', 'created_by_name'];

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'posted_at' => 'datetime', 'lock_version' => 'integer'];
    }

    /** @return HasMany<StockDocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class, 'document_id')->orderBy('line_no');
    }
}
