<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
    if ($user->is_platform_admin) {
        return true;
    }

    return $user->memberships()->where('workspace_id', $workspaceId)->exists();
});
