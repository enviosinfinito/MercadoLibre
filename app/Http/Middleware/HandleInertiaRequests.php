<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\TenantContext;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $workspaceId = TenantContext::workspaceId()
            ?? $request->session()->get('workspace_id');

        $workspace = null;
        if ($workspaceId) {
            $workspace = Workspace::query()
                ->whereKey($workspaceId)
                ->first(['id', 'name', 'slug', 'reporting_currency']);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'workspace' => $workspace,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'connection_invite_url' => fn () => $request->session()->get('connection_invite_url'),
            ],
        ];
    }
}
