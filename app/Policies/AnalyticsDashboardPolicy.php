<?php

namespace App\Policies;

use App\Models\AnalyticsDashboard;
use App\Models\User;
use App\Models\WorkspaceMembership;

class AnalyticsDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AnalyticsDashboard $dashboard): bool
    {
        if ($dashboard->isPlatformTemplate()) {
            return true;
        }

        if ($user->is_platform_admin) {
            return true;
        }

        if ($dashboard->visibility === AnalyticsDashboard::VISIBILITY_PERSONAL) {
            return (int) $dashboard->owner_user_id === (int) $user->id
                || $this->hasShare($user, $dashboard, 'view');
        }

        if ($dashboard->visibility === AnalyticsDashboard::VISIBILITY_WORKSPACE) {
            return $this->isMember($user, (int) $dashboard->workspace_id)
                || $this->hasShare($user, $dashboard, 'view');
        }

        return false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AnalyticsDashboard $dashboard): bool
    {
        if ($dashboard->isPlatformTemplate()) {
            return (bool) $user->is_platform_admin;
        }

        if ((int) $dashboard->owner_user_id === (int) $user->id) {
            return true;
        }

        if ($dashboard->visibility === AnalyticsDashboard::VISIBILITY_WORKSPACE) {
            return $this->isWorkspaceAdmin($user, (int) $dashboard->workspace_id)
                || $this->hasShare($user, $dashboard, 'edit');
        }

        return $this->hasShare($user, $dashboard, 'edit');
    }

    public function delete(User $user, AnalyticsDashboard $dashboard): bool
    {
        return $this->update($user, $dashboard);
    }

    public function manageTemplates(User $user): bool
    {
        return (bool) $user->is_platform_admin;
    }

    public function clone(User $user, AnalyticsDashboard $dashboard): bool
    {
        return $this->view($user, $dashboard);
    }

    private function isMember(User $user, int $workspaceId): bool
    {
        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->exists();
    }

    private function isWorkspaceAdmin(User $user, int $workspaceId): bool
    {
        return WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->whereIn('role_name', ['owner', 'admin'])
            ->exists();
    }

    private function hasShare(User $user, AnalyticsDashboard $dashboard, string $minPermission): bool
    {
        $membership = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $dashboard->workspace_id)
            ->first();

        $query = $dashboard->shares()
            ->where(function ($q) use ($user, $membership) {
                $q->where('user_id', $user->id);
                if ($membership) {
                    $q->orWhere('role_name', $membership->role_name);
                }
            });

        if ($minPermission === 'edit') {
            $query->where('permission', 'edit');
        }

        return $query->exists();
    }
}
