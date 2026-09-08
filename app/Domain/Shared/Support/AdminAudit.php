<?php

namespace App\Domain\Shared\Support;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AdminAudit
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function log(
        Request $request,
        string $action,
        ?Model $subject = null,
        ?int $workspaceId = null,
        ?array $meta = null,
    ): void {
        AuditEvent::query()->create([
            'workspace_id' => $workspaceId,
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
