<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Сообщение обсуждения проекта (legacy project_comments): side «client» or «staff»; an internal message is not shown to the
 * customer. Legacy has no editing or deleting of messages.
 *
 * @property int $id
 * @property int $project_id
 * @property string $side
 * @property bool $internal
 * @property string $text
 * @property string|null $author_login
 * @property string|null $author_name
 * @property Carbon|null $created_at
 */
class ProjectComment extends Model
{
    use HasPreciseTimestamps;

    protected $fillable = ['project_id', 'side', 'internal', 'text'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'internal' => 'boolean'];
    }
}
