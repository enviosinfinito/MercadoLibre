<?php

namespace App\Policies;

use App\Models\AnalyticsReport;
use App\Models\User;
use App\Models\WorkspaceMembership;

class AnalyticsReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AnalyticsReport $report): bool
    {
        if ((int) $report->owner_user_id === (int) $user->id) {
            return true;
        }

        if ($report->visibility === AnalyticsReport::VISIBILITY_WORKSPACE) {
            return WorkspaceMembership::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $report->workspace_id)
                ->exists();
        }

        return (bool) $user->is_platform_admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AnalyticsReport $report): bool
    {
        if ((int) $report->owner_user_id === (int) $user->id) {
            return true;
        }

        if ($report->visibility === AnalyticsReport::VISIBILITY_WORKSPACE) {
            return WorkspaceMembership::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $report->workspace_id)
                ->whereIn('role_name', ['owner', 'admin'])
                ->exists();
        }

        return false;
    }

    public function delete(User $user, AnalyticsReport $report): bool
    {
        return $this->update($user, $report);
    }
}
