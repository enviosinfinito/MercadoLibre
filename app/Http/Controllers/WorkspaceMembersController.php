<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Support\TenantContext;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMembersController extends Controller
{
    public function index(): Response
    {
        $workspaceId = TenantContext::id();

        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();

        return Inertia::render('Workspaces/Members', [
            'members' => $members,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_name' => ['nullable', 'string', 'max:50'],
        ]);

        $workspaceId = TenantContext::id();
        abort_unless($workspaceId !== null, 403);

        $role = $data['role_name'] ?? 'member';
        $email = Str::lower($data['email']);

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $invitationToken = Str::random(40);

            $user = User::query()->create([
                'name' => $data['name'] ?? Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
            ]);

            AuditEvent::query()->create([
                'workspace_id' => $workspaceId,
                'actor_user_id' => $request->user()?->id,
                'action' => 'workspace.member.invited',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'meta' => [
                    'email' => $email,
                    'role_name' => $role,
                    'invitation_token' => $invitationToken,
                ],
                'created_at' => now(),
            ]);
        }

        WorkspaceMembership::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
            ],
            [
                'role_name' => $role,
            ],
        );

        return redirect()
            ->back()
            ->with('success', "Invited {$email} as {$role}.");
    }
}
