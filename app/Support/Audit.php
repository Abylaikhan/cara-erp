<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;

class Audit
{
    /** @param array<string, mixed>|null $changes */
    public static function record(string $action, string $subjectType, int|string|null $subjectId, ?array $changes, ?User $user): AuditEvent
    {
        return AuditEvent::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId !== null ? (string) $subjectId : null,
            'changes' => $changes,
            'ip_address' => request()?->ip(),
        ]);
    }
}
