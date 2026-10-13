<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategoryMerge extends Model
{
    use HasPreciseTimestamps;

    public $timestamps = false;

    protected $fillable = ['from_id', 'to_id', 'user_id', 'references_before', 'references_after'];

    protected function casts(): array
    {
        return ['references_before' => 'array', 'references_after' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
