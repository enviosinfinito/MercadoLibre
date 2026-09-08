<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Support\AdminAudit;
use App\Http\Controllers\Controller;
use App\Http\Filters\Admin\UserFilterRegistry;
use App\Http\Requests\Admin\StoreUserMembershipRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateMembershipRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UsersIndexFilterRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(UsersIndexFilterRequest $request, UserFilterRegistry $filterRegistry): Response
    {
        $filters = $request->filters();

        $query = User::query()
            ->withCount('memberships')
            ->orderBy('name');

        $filterRegistry->apply($query, $filters);

        $users = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => null,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_platform_admin' => (bool) ($data['is_platform_admin'] ?? false),
            'email_verified_at' => now(),
        ]);

        AdminAudit::log($request, 'admin.user.created', $user, meta: [
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'User created.');
    }

    public function show(User $user): Response
    {
        $user->load([
            'memberships' => fn ($q) => $q->with('workspace:id,name,slug')->orderBy('id'),
        ]);

        $workspaces = Workspace::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
            'workspaces' => $workspaces,
        ]);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Form', [
            'user' => $user->only(['id', 'name', 'email', 'is_platform_admin']),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->is_platform_admin = (bool) ($data['is_platform_admin'] ?? false);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        AdminAudit::log($request, 'admin.user.updated', $user, meta: [
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin,
        ]);

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()?->id, 422, 'You cannot delete your own account.');

        AdminAudit::log($request, 'admin.user.deleted', $user, meta: [
            'email' => $user->email,
        ]);

        $user->memberships()->delete();
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User deleted.');
    }

    public function storeMembership(StoreUserMembershipRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $role = $data['role_name'] ?? 'member';

        $membership = WorkspaceMembership::query()->updateOrCreate(
            [
                'workspace_id' => $data['workspace_id'],
                'user_id' => $user->id,
            ],
            [
                'role_name' => $role,
            ],
        );

        AdminAudit::log($request, 'admin.user.membership.added', $membership, (int) $data['workspace_id'], [
            'user_id' => $user->id,
            'role_name' => $role,
        ]);

        return redirect()->back()->with('success', 'Membership added.');
    }

    public function updateMembership(
        UpdateMembershipRequest $request,
        User $user,
        WorkspaceMembership $membership,
    ): RedirectResponse {
        abort_unless((int) $membership->user_id === (int) $user->id, 404);

        $membership->update($request->validated());

        AdminAudit::log($request, 'admin.user.membership.updated', $membership, $membership->workspace_id, [
            'role_name' => $membership->role_name,
        ]);

        return redirect()->back()->with('success', 'Membership updated.');
    }

    public function destroyMembership(
        Request $request,
        User $user,
        WorkspaceMembership $membership,
    ): RedirectResponse {
        abort_unless((int) $membership->user_id === (int) $user->id, 404);

        AdminAudit::log($request, 'admin.user.membership.removed', $membership, $membership->workspace_id, [
            'user_id' => $user->id,
        ]);

        $membership->delete();

        return redirect()->back()->with('success', 'Membership removed.');
    }
}
