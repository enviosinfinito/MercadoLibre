<?php

namespace App\Domain\Integrations\Actions;

use App\Models\Connection;
use App\Models\ConnectionReputationSnapshot;
use Illuminate\Support\Carbon;

final class BuildConnectionReputationTimeline
{
    /**
     * @return array{
     *   points: list<array<string, mixed>>,
     *   milestones: list<array<string, mixed>>
     * }
     */
    public function execute(Connection $connection, ?int $days = null): array
    {
        $days = $days ?? (int) config('mercadolibre_reputation.timeline_days', 180);
        $from = Carbon::now()->subDays(max(1, $days))->startOfDay();

        $snapshots = ConnectionReputationSnapshot::query()
            ->where('connection_id', $connection->id)
            ->where('captured_at', '>=', $from)
            ->orderBy('captured_at')
            ->get();

        if ($snapshots->isEmpty()) {
            $snapshots = ConnectionReputationSnapshot::query()
                ->where('connection_id', $connection->id)
                ->orderByDesc('captured_at')
                ->limit(30)
                ->get()
                ->sortBy('captured_at')
                ->values();
        }

        $points = [];
        $milestones = [];

        foreach ($snapshots as $snap) {
            /** @var ConnectionReputationSnapshot $snap */
            $point = [
                'captured_at' => $snap->captured_at?->toIso8601String(),
                'capture_date' => $snap->capture_date?->toDateString(),
                'level_id' => $snap->level_id,
                'power_seller_status' => $snap->power_seller_status,
                'worst_band' => $snap->worst_band,
                'claims_rate' => $snap->claims_rate,
                'cancellations_rate' => $snap->cancellations_rate,
                'delayed_handling_rate' => $snap->delayed_handling_rate,
                'sales_completed' => $snap->sales_completed,
                'is_milestone' => (bool) $snap->is_milestone,
            ];
            $points[] = $point;

            if ($snap->is_milestone) {
                $payload = is_array($snap->payload) ? $snap->payload : [];
                $evaluation = is_array($payload['evaluation'] ?? null) ? $payload['evaluation'] : [];
                $milestones[] = [
                    'captured_at' => $snap->captured_at?->toIso8601String(),
                    'capture_date' => $snap->capture_date?->toDateString(),
                    'kind' => $snap->milestone_kind,
                    'label' => $snap->milestone_label,
                    'level_id' => $snap->level_id,
                    'power_seller_status' => $snap->power_seller_status,
                    'worst_band' => $snap->worst_band,
                    'claims_rate' => $snap->claims_rate,
                    'cancellations_rate' => $snap->cancellations_rate,
                    'delayed_handling_rate' => $snap->delayed_handling_rate,
                    'sales_completed' => $snap->sales_completed,
                    'drivers' => $evaluation['drivers'] ?? [],
                    'limiting_drivers' => $evaluation['limiting_drivers'] ?? [],
                ];
            }
        }

        return [
            'points' => $points,
            'milestones' => $milestones,
        ];
    }
}
