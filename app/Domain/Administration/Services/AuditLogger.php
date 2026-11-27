<?php

namespace App\Domain\Administration\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?int $userId = null,
    ): AuditLog {
        $req = $request ?? request();

        return AuditLog::query()->create([
            'user_id' => $userId ?? $req?->user()?->id,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip' => $req?->ip(),
            'user_agent' => $req?->userAgent() ? mb_substr((string) $req->userAgent(), 0, 500) : null,
            'created_at' => now('UTC'),
        ]);
    }
}
