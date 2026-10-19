<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of piece work (legacy wage_items, M18): who did which operation in which workshop for which order, how many and at
 * what rate. The day is kept as typed — almost always YYYY-MM-DD, a few lines carry free text.
 */
class PieceWorkItem extends Model
{
    use HasPreciseTimestamps;

    public const NUMBER_FIELDS = ['qty', 'rate'];
}
