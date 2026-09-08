<?php

namespace App\Domain\Ads\Services;

use App\Domain\Ads\MercadoLibre\ProductAdsWriteClient;
use App\Models\AdActionExecution;
use App\Models\AdAdvertiser;
use App\Models\AdCampaign;
use App\Models\AdWorkspaceSetting;
use App\Models\Connection;
use App\Models\User;

/**
 * Guided custom campaign creation (Fase D) — depends on write endpoints.
 */
final class AdsCampaignWizard
{
    public function __construct(
        private readonly ProductAdsWriteClient $writeClient,
        private readonly AdsProfitabilityService $profitability,
    ) {}

    /**
     * Preview grouping of scorecard items by margin band / status.
     *
     * @return array{
     *     suggested_roas_target: float,
     *     groups: list<array<string, mixed>>,
     *     write_enabled: bool
     * }
     */
    public function preview(int $workspaceId): array
    {
        $card = $this->profitability->scorecard($workspaceId, 30);
        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);

        $groups = [
            'high_margin_winners' => [
                'key' => 'high_margin_winners',
                'label' => 'Rentables (buen ROAS)',
                'items' => [],
            ],
            'needs_protection' => [
                'key' => 'needs_protection',
                'label' => 'Proteger (ROAS flojo / waste)',
                'items' => [],
            ],
            'learning_or_ok' => [
                'key' => 'learning_or_ok',
                'label' => 'Estables / verdes',
                'items' => [],
            ],
        ];

        foreach ($card['scorecard'] as $row) {
            if ($row['status'] === 'green' && ($row['roas_vs_target'] ?? 0) >= 1.2) {
                $groups['high_margin_winners']['items'][] = $row;
            } elseif (in_array($row['status'], ['red', 'yellow'], true)) {
                $groups['needs_protection']['items'][] = $row;
            } else {
                $groups['learning_or_ok']['items'][] = $row;
            }
        }

        return [
            'suggested_roas_target' => (float) $card['catalog_target_roas'],
            'catalog_target_acos' => (float) $card['catalog_target_acos'],
            'avg_margin_rate' => (float) $card['avg_margin_rate'],
            'groups' => array_values($groups),
            'write_enabled' => (bool) $settings->write_enabled,
            'kpis' => $card['kpis'],
        ];
    }

    /**
     * @param  array{
     *     connection_id: int,
     *     advertiser_id?: int|null,
     *     name: string,
     *     daily_budget: float,
     *     roas_target?: float|null,
     *     item_ids: list<string>,
     *     strategy?: string
     * }  $input
     * @return array{ok: bool, campaign_id?: int|null, external_campaign_id?: string|null, message: string, simulated?: bool}
     */
    public function create(int $workspaceId, array $input, ?User $user = null): array
    {
        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', (int) $input['connection_id'])
            ->first();

        if ($connection === null) {
            return ['ok' => false, 'message' => 'Conexión inválida.'];
        }

        $advertiser = null;
        if (! empty($input['advertiser_id'])) {
            $advertiser = AdAdvertiser::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', (int) $input['advertiser_id'])
                ->first();
        }
        $advertiser ??= AdAdvertiser::query()
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->first();

        if ($advertiser === null) {
            return ['ok' => false, 'message' => 'No hay advertiser sincronizado. Corré ads:sync primero.'];
        }

        $roasTarget = (float) ($input['roas_target'] ?? $this->profitability->suggestedTargetRoas($workspaceId));
        $roasTarget = max(1.0, min(35.0, $roasTarget));
        $budget = max(1.0, (float) $input['daily_budget']);
        $itemIds = array_values(array_filter(array_map('strval', $input['item_ids'] ?? [])));
        $name = trim((string) $input['name']);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Nombre de campaña requerido.'];
        }

        $payload = [
            'name' => $name,
            'status' => 'active',
            'strategy' => (string) ($input['strategy'] ?? 'PROFITABILITY'),
            'roas_target' => $roasTarget,
            'daily_budget' => $budget,
            'channel' => 'marketplace',
        ];

        if (! $settings->write_enabled) {
            AdActionExecution::query()->create([
                'workspace_id' => $workspaceId,
                'connection_id' => $connection->id,
                'acted_by_user_id' => $user?->id,
                'actor' => $user ? 'user' : 'system',
                'status' => 'simulated',
                'entity_type' => 'campaign',
                'entity_id' => 'wizard:'.$name,
                'action_type' => 'create_campaign',
                'before_payload' => null,
                'after_payload' => [
                    'payload' => $payload,
                    'item_ids' => $itemIds,
                    'note' => 'Write off: creá la campaña en ML Ads con estos valores.',
                ],
                'reason' => 'Wizard campaña (simulado)',
                'wrote_to_ml' => false,
            ]);

            return [
                'ok' => true,
                'campaign_id' => null,
                'external_campaign_id' => null,
                'message' => 'Checklist listo (write deshabilitado). Creá la campaña en ML con el presupuesto y ROAS sugeridos.',
                'simulated' => true,
                'payload' => $payload,
                'item_ids' => $itemIds,
            ];
        }

        $create = $this->writeClient->createCampaign($connection, $advertiser, $payload);
        if (! ($create['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($create['error'] ?? 'No se pudo crear la campaña en ML.'),
            ];
        }

        $externalId = (string) (
            $create['body']['id']
            ?? $create['body']['campaign_id']
            ?? ''
        );

        $campaign = null;
        if ($externalId !== '') {
            $campaign = AdCampaign::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'external_campaign_id' => $externalId,
                ],
                [
                    'workspace_id' => $workspaceId,
                    'ad_advertiser_id' => $advertiser->id,
                    'name' => $name,
                    'status' => 'active',
                    'strategy' => $payload['strategy'],
                    'meta' => array_merge($payload, is_array($create['body'] ?? null) ? $create['body'] : []),
                ],
            );

            if ($itemIds !== []) {
                $this->writeClient->addCampaignItems($connection, $campaign, $itemIds, $advertiser);
            }
        }

        AdActionExecution::query()->create([
            'workspace_id' => $workspaceId,
            'connection_id' => $connection->id,
            'acted_by_user_id' => $user?->id,
            'actor' => $user ? 'user' : 'system',
            'status' => 'executed',
            'entity_type' => 'campaign',
            'entity_id' => $externalId !== '' ? $externalId : 'wizard',
            'action_type' => 'create_campaign',
            'after_payload' => [
                'payload' => $payload,
                'item_ids' => $itemIds,
                'ml_response' => $create['body'] ?? null,
            ],
            'reason' => 'Wizard campaña',
            'wrote_to_ml' => true,
        ]);

        return [
            'ok' => true,
            'campaign_id' => $campaign?->id,
            'external_campaign_id' => $externalId !== '' ? $externalId : null,
            'message' => 'Campaña creada en Mercado Libre.',
            'simulated' => false,
        ];
    }
}
