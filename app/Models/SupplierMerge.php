<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/** One «Объединить» of suppliers: the kept supplier, the merged ones, filled fields and reference counts before/after. */
class SupplierMerge extends Model
{
    use HasPreciseTimestamps;

    public $timestamps = false;

    protected $fillable = ['supplier_id', 'merged_ids', 'user_id', 'filled_fields', 'references_before', 'references_after'];

    protected function casts(): array
    {
        return [
            'merged_ids' => 'array',
            'filled_fields' => 'array',
            'references_before' => 'array',
            'references_after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
