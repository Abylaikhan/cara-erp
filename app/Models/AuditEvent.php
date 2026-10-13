<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasPreciseTimestamps;

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
