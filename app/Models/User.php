<?php

namespace App\Models;

use App\Support\Erp;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $login
 * @property string $name
 * @property string|null $role
 * @property string|null $channel
 * @property bool $is_active
 * @property string|null $role_label
 * @property string|null $initials
 * @property string|null $color
 * @property list<string>|null $pages
 * @property int $lock_version
 */
#[Fillable(['login', 'name', 'email', 'password', 'role', 'channel', 'is_active', 'role_label', 'initials', 'color', 'pages'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'pages' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /** Two letters for the sidebar avatar: the account's own (legacy app_users.initials), else the first letters of the first two
     * words of the name. */
    public function initials(): string
    {
        // read raw: getAttribute('initials') of an account loaded without it would call this method as a relation
        $own = $this->getAttributes()['initials'] ?? null;
        if (filled($own)) {
            return (string) $own;
        }
        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters));
    }

    /** @return array{name: string, initials: string, color: string, roleLabel: string} */
    public function sidebarCard(): array
    {
        $role = Erp::role($this->role);

        return [
            'name' => $this->name,
            'initials' => $this->initials(),
            // legacy dbUsersApply: the account's colour and label over those of its role
            'color' => $this->getAttribute('color') ?: ($role['color'] ?? '#4f8ef7'),
            'roleLabel' => $this->getAttribute('role_label') ?: ($role['label'] ?? '—'),
        ];
    }
}
