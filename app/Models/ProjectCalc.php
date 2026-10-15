<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Версия расчёта проекта (legacy project_calcs): lines {name, attrs, qty, cost, price} with decimal strings, the total of
 * qty × price counted by the server. A version sent to the customer is not changed any more: saving makes the next version.
 *
 * @property int $id
 * @property int $project_id
 * @property int $version
 * @property list<array{name: string, attrs: string, qty: string, cost: string, price: string}> $items
 * @property string $total
 * @property string|null $markup_pct
 * @property Carbon|null $sent_at
 * @property string|null $created_by_name
 */
class ProjectCalc extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['project_id', 'version', 'items', 'total', 'markup_pct'];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'version' => 'integer',
            'items' => 'array',
            'total' => 'decimal:2',
            'markup_pct' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }
}
