<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Support\AdminAudit;
use App\Http\Controllers\Controller;
use App\Http\Filters\Admin\WorkspaceFilterRegistry;
use App\Http\Requests\Admin\StoreWorkspaceMemberRequest;
use App\Http\Requests\Admin\StoreWorkspaceRequest;
use App\Http\Requests\Admin\UpdateMembershipRequest;
use App\Http\Requests\Admin\UpdateWorkspaceRequest;
use App\Http\Requests\Admin\WorkspacesIndexFilterRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(WorkspacesIndexFilterRequest $request, WorkspaceFilterRegistry $filterRegistry): Response
    {
        $filters = $request->filters();

        $query = Workspace::query()
            ->withTrashed()
            ->withCount(['memberships', 'connections'])
            ->orderBy('name');

        $filterRegistry->apply($query, $filters);

        $workspaces = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Workspaces/Index', [
            'workspaces' => $workspaces,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Workspaces/Form', [
            'workspace' => null,
        ]);
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $workspace = Workspace::query()->create($request->validated());

        AdminAudit::log($request, 'admin.workspace.created', $workspace, $workspace->id);

        return redirect()
            ->route('admin.workspaces.show', $workspace)
            ->with('success', 'Workspace created.');
    }

    public function show(Workspace $workspace): Response
    {
        $workspace->loadCount(['memberships', 'connections']);
        $workspace->load([
            'memberships' => fn ($q) => $q->with('user:id,name,email')->orderBy('id'),
        ]);

        return Inertia::render('Admin/Workspaces/Show', [
            'workspace' => $workspace,
        ]);
    }

    public function edit(Workspace $workspace): Response
    {
        return Inertia::render('Admin/Workspaces/Form', [
            'workspace' => $workspace,
        ]);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace): RedirectResponse
    {
        $workspace->update($request->validated());

        AdminAudit::log($request, 'admin.workspace.updated', $workspace, $workspace->id, [
            'changes' => $request->validated(),
        ]);

        return redirect()
            ->route('admin.workspaces.show', $workspace)
            ->with('success', 'Workspace updated.');
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $workspace->delete();

        AdminAudit::log($request, 'admin.workspace.deleted', $workspace, $workspace->id);

        return redirect()
            ->route('admin.workspaces.index')
            ->with('success', 'Workspace archived.');
    }

    public function restore(Request $request, int $workspace): RedirectResponse
    {
        $model = Workspace::withTrashed()->whereKey($workspace)->firstOrFail();
        $model->restore();

        AdminAudit::log($request, 'admin.workspace.restored', $model, $model->id);

        return redirect()
            ->route('admin.workspaces.show', $model)
            ->with('success', 'Workspace restored.');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->session()->put('workspace_id', $workspace->id);

        AdminAudit::log($request, 'admin.workspace.switched', $workspace, $workspace->id);

        return redirect()
            ->route('dashboard')
            ->with('success', "Switched to {$workspace->name}.");
    }

    public function inviteMember(StoreWorkspaceMemberRequest $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validated();
        $role = $data['role_name'] ?? 'member';
        $email = Str::lower($data['email']);

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => $data['name'] ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        WorkspaceMembership::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            [
                'role_name' => $role,
            ],
        );

        AdminAudit::log($request, 'admin.workspace.member.invited', $user, $workspace->id, [
            'email' => $email,
            'role_name' => $role,
        ]);

        return redirect()
            ->back()
            ->with('success', "Invited {$email} as {$role}.");
    }

    public function updateMember(
        UpdateMembershipRequest $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
    ): RedirectResponse {
        abort_unless((int) $membership->workspace_id === (int) $workspace->id, 404);

        $membership->update($request->validated());

        AdminAudit::log($request, 'admin.workspace.member.updated', $membership, $workspace->id, [
            'role_name' => $membership->role_name,
            'user_id' => $membership->user_id,
        ]);

        return redirect()->back()->with('success', 'Member role updated.');
    }

    public function removeMember(
        Request $request,
        Workspace $workspace,
        WorkspaceMembership $membership,
    ): RedirectResponse {
        abort_unless((int) $membership->workspace_id === (int) $workspace->id, 404);

        AdminAudit::log($request, 'admin.workspace.member.removed', $membership, $workspace->id, [
            'user_id' => $membership->user_id,
        ]);

        $membership->delete();

        return redirect()->back()->with('success', 'Member removed.');
    }
}
