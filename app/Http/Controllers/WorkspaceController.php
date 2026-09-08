<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Workspace::query()->orderBy('name');

        if (! $user?->is_platform_admin) {
            $query->whereHas('memberships', fn ($q) => $q->where('user_id', $user?->id));
        }

        $workspaces = $query->get(['id', 'name', 'slug', 'reporting_currency']);

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        $workspace = Workspace::query()->whereKey($data['workspace_id'])->first();
        abort_unless($workspace !== null, 404);

        if (! $user?->is_platform_admin) {
            $allowed = $workspace->memberships()
                ->where('user_id', $user?->id)
                ->exists();

            abort_unless($allowed, 403);
        }

        $request->session()->put('workspace_id', (int) $data['workspace_id']);

        return redirect()->back();
    }
}
