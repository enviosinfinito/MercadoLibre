<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsDashboardShare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function store(Request $request, AnalyticsDashboard $dashboard): RedirectResponse
    {
        $this->authorize('update', $dashboard);
        abort_if($dashboard->isPlatformTemplate(), 422, 'Platform templates are globally visible.');

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role_name' => ['nullable', 'string', 'in:owner,admin,member,viewer,ops,finance'],
            'permission' => ['required', 'string', 'in:view,edit'],
        ]);

        if (empty($data['user_id']) && empty($data['role_name'])) {
            return back()->withErrors(['user_id' => 'Provide a user or role to share with.']);
        }

        AnalyticsDashboardShare::query()->updateOrCreate(
            [
                'analytics_dashboard_id' => $dashboard->id,
                'user_id' => $data['user_id'] ?? null,
                'role_name' => $data['role_name'] ?? null,
            ],
            [
                'permission' => $data['permission'],
            ],
        );

        return back()->with('success', 'Share updated');
    }

    public function destroy(AnalyticsDashboard $dashboard, AnalyticsDashboardShare $share): RedirectResponse
    {
        $this->authorize('update', $dashboard);
        abort_unless((int) $share->analytics_dashboard_id === (int) $dashboard->id, 404);
        $share->delete();

        return back()->with('success', 'Share removed');
    }
}
