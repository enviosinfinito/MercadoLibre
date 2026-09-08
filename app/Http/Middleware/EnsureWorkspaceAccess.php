<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\TenantContext;
use App\Models\WorkspaceMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = TenantContext::workspaceId();

        if ($user === null || $workspaceId === null) {
            abort(403, 'Workspace context required.');
        }

        if ($user->is_platform_admin) {
            return $next($request);
        }

        $allowed = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->exists();

        if (! $allowed) {
            abort(403, 'You do not have access to this workspace.');
        }

        return $next($request);
    }
}
