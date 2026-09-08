<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->session()->get('workspace_id')
            ?? $request->header('X-Workspace-Id')
            ?? $request->input('workspace_id');

        if ($workspaceId !== null && $workspaceId !== '' && ! Workspace::query()->whereKey((int) $workspaceId)->exists()) {
            $request->session()->forget('workspace_id');
            $workspaceId = null;
        }

        if (($workspaceId === null || $workspaceId === '') && $request->user() !== null) {
            $workspaceId = $this->resolveDefaultWorkspaceId((int) $request->user()->id);

            if ($workspaceId !== null) {
                $request->session()->put('workspace_id', $workspaceId);
            }
        }

        if ($workspaceId !== null && $workspaceId !== '') {
            TenantContext::set((int) $workspaceId);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }

    private function resolveDefaultWorkspaceId(int $userId): ?int
    {
        $membershipWorkspaceId = WorkspaceMembership::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->value('workspace_id');

        return $membershipWorkspaceId !== null ? (int) $membershipWorkspaceId : null;
    }
}
