<?php

namespace App\Domain\Ads\Services;

use App\Models\AdActionProposal;
use App\Models\AdRule;
use App\Models\AdWorkspaceSetting;

/**
 * Evaluates readable ad rules against scorecard rows → proposals (shadow by default).
 */
final class AdsRulesEvaluator
{
    public function __construct(
        private readonly AdsProfitabilityService $profitability,
        private readonly AdsGuardrails $guardrails,
    ) {}

    /**
     * @return array{
     *     proposals_created: int,
     *     proposals_skipped: int,
     *     auto_queued: int,
     *     mode: string
     * }
     */
    public function execute(int $workspaceId): array
    {
        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        if (! $this->guardrails->canEvaluate($settings)) {
            return [
                'proposals_created' => 0,
                'proposals_skipped' => 0,
                'auto_queued' => 0,
                'mode' => (string) $settings->autopilot_mode,
            ];
        }

        $rules = AdRule::query()
            ->where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        if ($rules->isEmpty()) {
            return [
                'proposals_created' => 0,
                'proposals_skipped' => 0,
                'auto_queued' => 0,
                'mode' => (string) $settings->autopilot_mode,
            ];
        }

        $card = $this->profitability->scorecard($workspaceId);
        $created = 0;
        $skipped = 0;
        $autoQueued = 0;
        $pauses = 0;
        $totalSpend = max(0.01, (float) $card['kpis']['spend']);
        $pausedSpend = 0.0;

        // Expire stale pending duplicates softly by fingerprint.
        foreach ($card['scorecard'] as $row) {
            foreach ($rules as $rule) {
                if (! $this->ruleAppliesToRow($rule, $row)) {
                    continue;
                }
                if (! $this->matchesCondition($rule->condition_json ?? [], $row)) {
                    continue;
                }

                $action = $rule->action_json ?? [];
                $actionType = (string) ($action['type'] ?? 'suggest_only');
                $payload = $this->buildActionPayload($action, $row, $workspaceId);
                $entity = $this->resolveEntity($actionType, $row);

                $fingerprint = [
                    'workspace_id' => $workspaceId,
                    'entity_type' => $entity['type'],
                    'entity_id' => $entity['id'],
                    'action_type' => $actionType,
                    'status' => 'pending',
                ];

                $exists = AdActionProposal::query()
                    ->where($fingerprint)
                    ->where('ad_rule_id', $rule->id)
                    ->where('created_at', '>=', now()->subDay())
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $title = $this->titleFor($actionType, $row);
                $priority = $row['status'] === 'red' ? 'high' : ($row['status'] === 'yellow' ? 'medium' : 'low');
                $marginRate = (float) ($row['margin_rate'] ?? 0.25);
                $impact = null;
                if ($actionType === 'pause_ad' || $actionType === 'pause_campaign') {
                    $impact = (float) $row['cost'];
                }

                $marginProtected = round((float) $row['cost'] * max(0, $marginRate), 2);
                $impactMoney = number_format((float) $row['cost'], 0, '.', ',');
                $statusReason = (string) ($row['status_reason'] ?? '');
                $reasonDetail = $statusReason !== ''
                    ? sprintf(
                        '%s Si hacés esto, dejás de gastar ~$%s en algo que no rinde (protege ~$%s de margen est.).',
                        $statusReason,
                        $impactMoney,
                        number_format($marginProtected, 0, '.', ','),
                    )
                    : sprintf(
                        '%s · ventas por ads %sx vs meta %sx. Si hacés esto, dejás de gastar ~$%s en algo que no rinde.',
                        $rule->description ?: $rule->name,
                        $row['roas'],
                        $row['target_roas'],
                        $impactMoney,
                    );

                $proposal = new AdActionProposal([
                    'workspace_id' => $workspaceId,
                    'connection_id' => $row['connection_id'] ?: null,
                    'ad_rule_id' => $rule->id,
                    'status' => 'pending',
                    'entity_type' => $entity['type'],
                    'entity_id' => $entity['id'],
                    'ml_item_id' => $row['ml_item_id'],
                    'action_type' => $actionType,
                    'action_payload' => $payload,
                    'title' => $title,
                    'reason' => $reasonDetail,
                    'priority' => $priority,
                    'estimated_impact_amount' => $impact,
                    'metrics_snapshot' => array_merge($row, [
                        'estimated_margin_protected' => $marginProtected,
                    ]),
                    'expires_at' => now()->addDays(7),
                ]);

                $gate = $this->guardrails->allowProposal(
                    $workspaceId,
                    $proposal,
                    $pauses,
                    $pausedSpend / $totalSpend,
                );

                if (! $gate['allowed']) {
                    $skipped++;
                    continue;
                }

                $proposal->save();
                $created++;

                if (str_starts_with($actionType, 'pause_')) {
                    $pauses++;
                    $pausedSpend += (float) $row['cost'];
                }

                $mode = (string) $settings->autopilot_mode;
                if ($mode === 'auto' && $rule->auto_execute && ! $settings->kill_switch) {
                    $autoQueued++;
                    // Mark approved for the executor job/command to pick up.
                    $proposal->forceFill(['status' => 'approved'])->save();
                }
            }
        }

        return [
            'proposals_created' => $created,
            'proposals_skipped' => $skipped,
            'auto_queued' => $autoQueued,
            'mode' => (string) $settings->autopilot_mode,
        ];
    }

    private function ruleAppliesToRow(AdRule $rule, array $row): bool
    {
        if ((float) $row['cost'] < (float) $rule->min_spend) {
            return false;
        }
        if ((int) $row['clicks'] < (int) $rule->min_clicks) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $row
     */
    private function matchesCondition(array $condition, array $row): bool
    {
        $type = (string) ($condition['type'] ?? '');

        return match ($type) {
            'and' => collect($condition['conditions'] ?? [])->every(
                fn ($c) => is_array($c) && $this->matchesCondition($c, $row),
            ),
            'or' => collect($condition['conditions'] ?? [])->contains(
                fn ($c) => is_array($c) && $this->matchesCondition($c, $row),
            ),
            'stock_eq' => ($row['stock_qty'] ?? null) !== null
                && (int) $row['stock_qty'] === (int) ($condition['value'] ?? 0),
            'units_eq' => (float) $row['attributed_units'] === (float) ($condition['value'] ?? 0),
            'spend_gte' => (float) $row['cost'] >= (float) ($condition['value'] ?? 0),
            'roas_lt_target_ratio' => ($row['target_roas'] ?? 0) > 0
                && (float) $row['roas'] < (float) $row['target_roas'] * (float) ($condition['ratio'] ?? 1),
            'roas_gte_target_ratio' => ($row['target_roas'] ?? 0) > 0
                && (float) $row['roas'] >= (float) $row['target_roas'] * (float) ($condition['ratio'] ?? 1),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildActionPayload(array $action, array $row, int $workspaceId): array
    {
        $type = (string) ($action['type'] ?? 'suggest_only');

        return match ($type) {
            'set_roas_target' => [
                'roas_target' => (float) ($row['target_roas'] ?? $this->profitability->suggestedTargetRoas($workspaceId, $row['ml_item_id'])),
                'external_campaign_id' => $row['external_campaign_id'],
                'ad_campaign_id' => $row['ad_campaign_id'],
            ],
            'set_budget' => [
                'delta_pct' => (float) ($action['delta_pct'] ?? 0.1),
                'external_campaign_id' => $row['external_campaign_id'],
                'ad_campaign_id' => $row['ad_campaign_id'],
            ],
            'pause_campaign', 'activate_campaign' => [
                'ad_campaign_id' => $row['ad_campaign_id'],
                'external_campaign_id' => $row['external_campaign_id'],
            ],
            default => [
                'ml_item_id' => $row['ml_item_id'],
                'ad_campaign_id' => $row['ad_campaign_id'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{type: string, id: string}
     */
    private function resolveEntity(string $actionType, array $row): array
    {
        if (in_array($actionType, ['pause_campaign', 'activate_campaign', 'set_roas_target', 'set_budget'], true)) {
            $id = (string) ($row['ad_campaign_id'] ?? $row['external_campaign_id'] ?? $row['ml_item_id']);

            return ['type' => 'campaign', 'id' => $id];
        }

        return ['type' => 'ad', 'id' => (string) $row['ml_item_id']];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function titleFor(string $actionType, array $row): string
    {
        $title = (string) ($row['title'] ?? $row['ml_item_id']);
        $short = mb_strlen($title) > 42 ? mb_substr($title, 0, 39).'…' : $title;
        $cost = number_format((float) $row['cost'], 0, '.', ',');

        return match ($actionType) {
            'pause_ad' => "Pausar “{$short}”: dejás de gastar ~\${$cost} sin retorno",
            'pause_campaign' => "Pausar campaña de “{$short}” para cuidar ganancia",
            'activate_ad' => "Reactivar “{$short}” (margen sano)",
            'set_roas_target' => "Ajustar meta de “{$short}” a {$row['target_roas']}x (según tu ganancia)",
            'set_budget' => "Subir presupuesto de “{$short}” (ya rinde bien)",
            default => "Revisar “{$short}”",
        };
    }
}
