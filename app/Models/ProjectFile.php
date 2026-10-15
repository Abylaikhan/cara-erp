<?php

namespace App\Models;

use App\Models\Concerns\HasPreciseTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Файл проекта (legacy project_files): side «customer» (salon/dealer) or «designer» (production); an internal file is not shown
 * to the customer. The content is on the private disk and is given only to a user who may see it.
 *
 * @property int $id
 * @property int $project_id
 * @property string $side
 * @property bool $internal
 * @property string $file_name
 * @property string $storage_path
 * @property string $mime
 * @property int $size
 * @property int|null $uploaded_by_id
 * @property string|null $uploaded_by_login
 * @property string|null $uploaded_by_name
 * @property Carbon|null $created_at
 */
class ProjectFile extends Model
{
    use HasPreciseTimestamps;

    /** Types the browser may show in place (a thumbnail); anything else is downloaded. SVG is not here: it can carry script. */
    public const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    protected $fillable = ['project_id', 'side', 'internal', 'file_name', 'storage_path', 'mime', 'size'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'internal' => 'boolean', 'size' => 'integer', 'uploaded_by_id' => 'integer'];
    }

    public function isImage(): bool
    {
        return in_array($this->mime, self::INLINE_TYPES, true);
    }
}
