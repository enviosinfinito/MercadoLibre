<?php

namespace Database\Seeders;

use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsWidget;
use App\Models\User;
use Illuminate\Database\Seeder;

class AnalyticsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('is_platform_admin', true)->first();

        $overview = AnalyticsDashboard::query()->updateOrCreate(
            [
                'visibility' => AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE,
                'slug' => 'overview-comercial',
            ],
            [
                'workspace_id' => null,
                'owner_user_id' => $admin?->id,
                'name' => 'Overview comercial',
                'description' => 'Plantilla global: pedidos, revenue y profit del workspace.',
                'layout' => ['cols' => 12],
                'global_filters' => null,
                'is_home' => true,
            ],
        );

        $overview->widgets()->delete();

        $overviewWidgets = [
            [
                'type' => 'kpi',
                'title' => 'Revenue esperado',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'Profit esperado',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'Pedidos',
                'query' => [
                    'dataset' => 'orders',
                    'dimensions' => [],
                    'measures' => [['field' => 'order_count', 'agg' => 'count', 'alias' => 'orders']],
                    'filters' => [],
                    'limit' => 1,
                ],
                'grid' => ['x' => 6, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'Stock disponible',
                'query' => [
                    'dataset' => 'inventory',
                    'dimensions' => [],
                    'measures' => [['field' => 'available', 'agg' => 'sum', 'alias' => 'available_sum']],
                    'filters' => [],
                    'limit' => 1,
                ],
                'grid' => ['x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'line',
                'title' => 'Profit por día',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => ['ordered_at:day'],
                    'measures' => [
                        ['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum'],
                        ['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum'],
                    ],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'sort' => [['field' => 'ordered_at_day', 'dir' => 'asc']],
                    'limit' => 90,
                ],
                'grid' => ['x' => 0, 'y' => 2, 'w' => 8, 'h' => 4],
            ],
            [
                'type' => 'bar',
                'title' => 'Pedidos por status',
                'query' => [
                    'dataset' => 'orders',
                    'dimensions' => ['status'],
                    'measures' => [['field' => 'order_count', 'agg' => 'count', 'alias' => 'orders']],
                    'filters' => [],
                    'sort' => [['field' => 'orders', 'dir' => 'desc']],
                    'limit' => 20,
                ],
                'grid' => ['x' => 8, 'y' => 2, 'w' => 4, 'h' => 4],
            ],
        ];

        foreach ($overviewWidgets as $i => $widget) {
            AnalyticsWidget::query()->create([
                ...$widget,
                'analytics_dashboard_id' => $overview->id,
                'viz_options' => ['valueKey' => $widget['query']['measures'][0]['alias'] ?? null],
                'sort_order' => $i,
            ]);
        }

        $pnl = AnalyticsDashboard::query()->updateOrCreate(
            [
                'visibility' => AnalyticsDashboard::VISIBILITY_PLATFORM_TEMPLATE,
                'slug' => 'pnl-esperado',
            ],
            [
                'workspace_id' => null,
                'owner_user_id' => $admin?->id,
                'name' => 'P&L esperado',
                'description' => 'Plantilla global de P&L: revenue, fees, COGS y margen.',
                'layout' => ['cols' => 12],
                'global_filters' => ['date_field' => 'ordered_at'],
                'is_home' => false,
            ],
        );

        $pnl->widgets()->delete();

        $pnlWidgets = [
            [
                'type' => 'kpi',
                'title' => 'Revenue',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'Fees',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [['field' => 'fees', 'agg' => 'sum', 'alias' => 'fees_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 3, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'COGS',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [['field' => 'cogs', 'agg' => 'sum', 'alias' => 'cogs_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 6, 'y' => 0, 'w' => 3, 'h' => 2],
            ],
            [
                'type' => 'kpi',
                'title' => 'Margen %',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => [],
                    'measures' => [
                        ['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum'],
                        ['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum'],
                        ['formula' => 'sum(profit)/sum(revenue)', 'alias' => 'margin_pct'],
                    ],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 1,
                ],
                'grid' => ['x' => 9, 'y' => 0, 'w' => 3, 'h' => 2],
                'viz_options' => ['valueKey' => 'margin_pct', 'format' => 'percent'],
            ],
            [
                'type' => 'table',
                'title' => 'P&L por conexión',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => ['connection_id'],
                    'measures' => [
                        ['field' => 'revenue', 'agg' => 'sum', 'alias' => 'revenue_sum'],
                        ['field' => 'fees', 'agg' => 'sum', 'alias' => 'fees_sum'],
                        ['field' => 'cogs', 'agg' => 'sum', 'alias' => 'cogs_sum'],
                        ['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum'],
                    ],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'sort' => [['field' => 'profit_sum', 'dir' => 'desc']],
                    'limit' => 100,
                ],
                'grid' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 5],
            ],
            [
                'type' => 'pie',
                'title' => 'Incompletos vs completos',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => ['is_incomplete'],
                    'measures' => [['field' => 'id', 'agg' => 'count', 'alias' => 'snapshots']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'limit' => 10,
                ],
                'grid' => ['x' => 0, 'y' => 7, 'w' => 6, 'h' => 4],
            ],
            [
                'type' => 'bar',
                'title' => 'Profit mensual',
                'query' => [
                    'dataset' => 'profit',
                    'dimensions' => ['ordered_at:month'],
                    'measures' => [['field' => 'profit', 'agg' => 'sum', 'alias' => 'profit_sum']],
                    'filters' => [['field' => 'stage', 'op' => 'eq', 'value' => 'expected']],
                    'sort' => [['field' => 'ordered_at_month', 'dir' => 'asc']],
                    'limit' => 24,
                ],
                'grid' => ['x' => 6, 'y' => 7, 'w' => 6, 'h' => 4],
            ],
        ];

        foreach ($pnlWidgets as $i => $widget) {
            AnalyticsWidget::query()->create([
                'analytics_dashboard_id' => $pnl->id,
                'type' => $widget['type'],
                'title' => $widget['title'],
                'query' => $widget['query'],
                'viz_options' => $widget['viz_options'] ?? ['valueKey' => $widget['query']['measures'][0]['alias'] ?? null],
                'grid' => $widget['grid'],
                'sort_order' => $i,
            ]);
        }
    }
}
