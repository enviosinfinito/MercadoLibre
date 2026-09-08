<?php

namespace App\Domain\Ads\Services;

use App\Models\AdActionExecution;
use App\Models\AdActionProposal;
use App\Models\AdCampaign;
use App\Models\AdWorkspaceSetting;
use Illuminate\Support\Carbon;

final class AdsGuardrails
{
    public function canEvaluate(AdWorkspaceSetting $settings): bool
    {
        if ($settings->kill_switch) {
            return false;
        }

        $breaker = (int) config('ads.guardrails.write_failure_circuit_breaker', 5);

        return (int) $settings->write_failures < $breaker || ! $settings->write_enabled;
    }

    /**
     * @return array{allowed: bool, reason?: string}
     */
    public function allowProposal(
        int $workspaceId,
        AdActionProposal $proposal,
        int $pausesThisCycle,
        float $spendPausedShare,
    ): array {
        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        if ($settings->kill_switch) {
            return ['allowed' => false, 'reason' => 'Kill switch activo en el workspace.'];
        }

        $isPause = str_starts_with($proposal->action_type, 'pause_');
        if ($isPause) {
            $maxPauses = (int) config('ads.guardrails.max_pauses_per_cycle', 25);
            if ($pausesThisCycle >= $maxPauses) {
                return ['allowed' => false, 'reason' => "Tope de pausas por ciclo ({$maxPauses})."];
            }
            $maxShare = (float) config('ads.guardrails.max_spend_share_paused_per_cycle', 0.35);
            if ($spendPausedShare >= $maxShare) {
                return ['allowed' => false, 'reason' => 'Tope de % de gasto pausado por ciclo.'];
            }
        }

        $cooldownHours = (int) config('ads.guardrails.entity_cooldown_hours', 48);
        $recent = AdActionExecution::query()
            ->where('workspace_id', $workspaceId)
            ->where('entity_type', $proposal->entity_type)
            ->where('entity_id', $proposal->entity_id)
            ->where('status', 'executed')
            ->where('created_at', '>=', now()->subHours($cooldownHours))
            ->exists();

        if ($recent) {
            return ['allowed' => false, 'reason' => "Cooldown de {$cooldownHours}h en esta entidad."];
        }

        if ($proposal->entity_type === 'campaign' && $isPause) {
            $learning = $this->isInLearningPeriod((int) $proposal->entity_id);
            $extremeWaste = (float) ($proposal->metrics_snapshot['cost'] ?? 0)
                >= (float) config('ads.guardrails.min_spend_for_waste_pause', 50)
                && (float) ($proposal->metrics_snapshot['attributed_units'] ?? 0) <= 0;

            if ($learning && ! $extremeWaste) {
                return ['allowed' => false, 'reason' => 'Campaña en ventana de aprendizaje (<14 días).'];
            }
        }

        return ['allowed' => true];
    }

    private function isInLearningPeriod(int $campaignInternalId): bool
    {
        $campaign = AdCampaign::query()->find($campaignInternalId);
        if ($campaign === null) {
            return false;
        }

        $learningDays = (int) config('ads.learning_days', 14);
        $created = $campaign->created_at instanceof Carbon
            ? $campaign->created_at
            : Carbon::parse((string) $campaign->created_at);

        // Prefer ML date_created from meta when present.
        $metaCreated = $campaign->meta['date_created'] ?? null;
        if (is_string($metaCreated) && $metaCreated !== '') {
            try {
                $created = Carbon::parse($metaCreated);
            } catch (\Throwable) {
                // keep local created_at
            }
        }

        return $created->greaterThan(now()->subDays($learningDays));
    }
}
