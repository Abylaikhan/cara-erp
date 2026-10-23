<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of a delivery note of «Рабочий лист реализации» (legacy sales_worksheet): the note (doc_id, number, date, customer,
 * driver), the product of an order with its quantity, price and amount, and whether the note is posted and delivered.
 *
 * @property int $id
 * @property string $uuid
 * @property string $doc_id
 */
class SalesWorksheetRow extends Model
{
    use HasPreciseTimestamps;

    public const NUMBER_FIELDS = ['qty', 'price', 'amount'];

    protected function casts(): array
    {
        return ['doc_date' => 'date:Y-m-d', 'attrs_short' => 'boolean', 'posted' => 'boolean', 'delivered' => 'boolean',
            'posted_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
