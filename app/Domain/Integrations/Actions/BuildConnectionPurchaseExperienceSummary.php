<?php

namespace App\Domain\Integrations\Actions;

use App\Models\ChannelListing;
use App\Models\Connection;
use Illuminate\Support\Facades\DB;

final class BuildConnectionPurchaseExperienceSummary
{
    /**
     * @return array{
     *   enabled: bool,
     *   totals_by_color: array<string, int>,
     *   without_data: int,
     *   top_problems: list<array{key: string, title: string, count: int}>
     * }
     */
    public function execute(Connection $connection, bool $peSyncEnabled): array
    {
        $base = ChannelListing::query()
            ->where('connection_id', $connection->id)
            ->where('status', 'active');

        $totals = (clone $base)
            ->whereNotNull('pe_color')
            ->select('pe_color', DB::raw('count(*) as aggregate'))
            ->groupBy('pe_color')
            ->pluck('aggregate', 'pe_color')
            ->map(fn ($n) => (int) $n)
            ->all();

        $withoutData = (clone $base)->whereNull('pe_color')->count();

        $listings = (clone $base)
            ->whereNotNull('purchase_experience')
            ->get(['id', 'purchase_experience']);

        $problemCounts = [];
        foreach ($listings as $listing) {
            $pe = is_array($listing->purchase_experience) ? $listing->purchase_experience : [];
            $problems = is_array($pe['metrics_details']['problems'] ?? null)
                ? $pe['metrics_details']['problems']
                : [];
            foreach ($problems as $problem) {
                if (! is_array($problem)) {
                    continue;
                }
                $l2 = is_array($problem['level_two'] ?? null) ? $problem['level_two'] : [];
                $l3 = is_array($problem['level_three'] ?? null) ? $problem['level_three'] : [];
                $key = (string) ($l3['key'] ?? $l2['key'] ?? $problem['key'] ?? 'unknown');
                $title = (string) ($l3['title'] ?? $l2['title'] ?? $key);
                if (! isset($problemCounts[$key])) {
                    $problemCounts[$key] = ['key' => $key, 'title' => $title, 'count' => 0];
                }
                $problemCounts[$key]['count']++;
            }
        }

        usort($problemCounts, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'enabled' => $peSyncEnabled,
            'totals_by_color' => $totals,
            'without_data' => $withoutData,
            'top_problems' => array_values(array_slice($problemCounts, 0, 5)),
        ];
    }
}
