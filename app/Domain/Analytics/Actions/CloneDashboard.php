<?php

namespace App\Domain\Analytics\Actions;

use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsWidget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CloneDashboard
{
    public function handle(
        AnalyticsDashboard $source,
        int $workspaceId,
        User $user,
        string $visibility = AnalyticsDashboard::VISIBILITY_PERSONAL,
        ?string $name = null,
    ): AnalyticsDashboard {
        return DB::transaction(function () use ($source, $workspaceId, $user, $visibility, $name) {
            $source->loadMissing('widgets');

            $clone = AnalyticsDashboard::query()->create([
                'workspace_id' => $workspaceId,
                'owner_user_id' => $user->id,
                'name' => $name ?? ($source->name.' (copy)'),
                'description' => $source->description,
                'visibility' => $visibility,
                'layout' => $source->layout,
                'global_filters' => $source->global_filters,
                'cloned_from_id' => $source->id,
                'is_home' => false,
            ]);

            foreach ($source->widgets as $index => $widget) {
                AnalyticsWidget::query()->create([
                    'analytics_dashboard_id' => $clone->id,
                    'type' => $widget->type,
                    'title' => $widget->title,
                    'query' => $widget->query,
                    'viz_options' => $widget->viz_options,
                    'grid' => $widget->grid,
                    'sort_order' => $widget->sort_order ?? $index,
                ]);
            }

            return $clone->load('widgets');
        });
    }
}
