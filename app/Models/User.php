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
 */
#[Fillable(['login', 'name', 'email', 'password', 'role', 'channel', 'is_active'])]
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
        ];
    }

    /** Two letters for the sidebar avatar: first letters of the first two words of the name. */
    public function initials(): string
    {
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
            'color' => $role['color'] ?? '#4f8ef7',
            'roleLabel' => $role['label'] ?? '—',
        ];
    }
}
