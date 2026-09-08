<?php

namespace App\Domain\Ads\Services;

use App\Domain\Ads\MercadoLibre\ProductAdsWriteClient;
use App\Models\AdActionExecution;
use App\Models\AdActionProposal;
use App\Models\AdCampaign;
use App\Models\AdWorkspaceSetting;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdsActionExecutor
{
    public function __construct(
        private readonly ProductAdsWriteClient $writeClient,
    ) {}

    /**
     * @return array{status: string, execution_id: int|null, wrote_to_ml: bool, message: string}
     */
    public function executeProposal(
        AdActionProposal $proposal,
        ?User $user = null,
        bool $forceWrite = false,
    ): array {
        $settings = AdWorkspaceSetting::forWorkspace((int) $proposal->workspace_id);

        if ($settings->kill_switch) {
            return [
                'status' => 'failed',
                'execution_id' => null,
                'wrote_to_ml' => false,
                'message' => 'Kill switch activo.',
            ];
        }

        $mode = (string) $settings->autopilot_mode;
        $shouldWrite = $forceWrite
            || ($settings->write_enabled && in_array($mode, ['approve', 'auto'], true));

        // Shadow: always simulate
        if ($mode === 'shadow' && ! $forceWrite) {
            $shouldWrite = false;
        }

        return DB::transaction(function () use ($proposal, $user, $settings, $shouldWrite, $mode) {
            $before = [
                'action_type' => $proposal->action_type,
                'payload' => $proposal->action_payload,
                'metrics' => $proposal->metrics_snapshot,
            ];

            $wrote = false;
            $after = $before;
            $undo = null;
            $error = null;
            $status = 'simulated';

            if ($shouldWrite) {
                try {
                    $result = $this->writeToMl($proposal);
                    $wrote = (bool) ($result['ok'] ?? false);
                    $after = array_merge($before, ['ml_response' => $result['body'] ?? null, 'path' => $result['path'] ?? null]);
                    $undo = $result['undo'] ?? null;
                    if ($wrote) {
                        $status = 'executed';
                        $settings->forceFill(['write_failures' => 0])->save();
                    } else {
                        $status = 'failed';
                        $error = (string) ($result['error'] ?? 'Write failed');
                        $settings->forceFill([
                            'write_failures' => (int) $settings->write_failures + 1,
                        ])->save();
                    }
                } catch (Throwable $e) {
                    $status = 'failed';
                    $error = mb_substr($e->getMessage(), 0, 500);
                    $settings->forceFill([
                        'write_failures' => (int) $settings->write_failures + 1,
                    ])->save();
                }
            } else {
                $status = 'simulated';
                $after['note'] = $mode === 'shadow'
                    ? 'Shadow mode: no se escribió en Mercado Libre. Aplicá manualmente o activá approve/auto con write.'
                    : 'Write deshabilitado: checklist para aplicar en el panel de ML Ads.';
            }

            $execution = AdActionExecution::query()->create([
                'workspace_id' => $proposal->workspace_id,
                'connection_id' => $proposal->connection_id,
                'ad_action_proposal_id' => $proposal->id,
                'ad_rule_id' => $proposal->ad_rule_id,
                'acted_by_user_id' => $user?->id,
                'actor' => $user ? 'user' : 'system',
                'status' => $status,
                'entity_type' => $proposal->entity_type,
                'entity_id' => $proposal->entity_id,
                'ml_item_id' => $proposal->ml_item_id,
                'action_type' => $proposal->action_type,
                'before_payload' => $before,
                'after_payload' => $after,
                'undo_payload' => $undo,
                'reason' => $proposal->reason,
                'error_redacted' => $error,
                'wrote_to_ml' => $wrote,
            ]);

            $proposal->forceFill([
                'status' => $status === 'failed' ? 'failed' : 'executed',
            ])->save();

            return [
                'status' => $status,
                'execution_id' => (int) $execution->id,
                'wrote_to_ml' => $wrote,
                'message' => $error ?? ($wrote
                    ? 'Acción aplicada en Mercado Libre.'
                    : 'Registrado sin escritura (shadow/suggest).'),
            ];
        });
    }

    /**
     * @return array{status: string, message: string}
     */
    public function undo(AdActionExecution $execution, ?User $user = null): array
    {
        if ($execution->status === 'undone') {
            return ['status' => 'undone', 'message' => 'Ya estaba deshecho.'];
        }

        $settings = AdWorkspaceSetting::forWorkspace((int) $execution->workspace_id);
        $undo = $execution->undo_payload;
        if (! is_array($undo) || $undo === [] || ! $execution->wrote_to_ml || ! $settings->write_enabled) {
            $execution->forceFill([
                'status' => 'undone',
                'undone_at' => now(),
            ])->save();

            return [
                'status' => 'undone',
                'message' => 'Marcado como deshecho localmente. Si hubo cambio en ML, revertí manualmente en el panel.',
            ];
        }

        $connection = Connection::query()->find($execution->connection_id);
        if ($connection === null) {
            return ['status' => 'failed', 'message' => 'Conexión no encontrada.'];
        }

        $result = $this->applyUndoPayload($connection, $execution, $undo);
        if (! ($result['ok'] ?? false)) {
            return [
                'status' => 'failed',
                'message' => (string) ($result['error'] ?? 'No se pudo deshacer en ML.'),
            ];
        }

        $execution->forceFill([
            'status' => 'undone',
            'undone_at' => now(),
            'after_payload' => array_merge($execution->after_payload ?? [], ['undo_result' => $result]),
        ])->save();

        return ['status' => 'undone', 'message' => 'Acción deshecha en Mercado Libre.'];
    }

    /**
     * @return array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string, undo?: array<string, mixed>}
     */
    private function writeToMl(AdActionProposal $proposal): array
    {
        $connection = Connection::query()->find($proposal->connection_id);
        if ($connection === null) {
            return ['ok' => false, 'error' => 'Sin connection_id en la propuesta.'];
        }

        $payload = $proposal->action_payload ?? [];

        return match ($proposal->action_type) {
            'pause_ad' => $this->withUndo(
                $this->writeClient->pauseAd($connection, (string) $proposal->ml_item_id),
                ['type' => 'activate_ad', 'ml_item_id' => $proposal->ml_item_id],
            ),
            'activate_ad' => $this->withUndo(
                $this->writeClient->activateAd($connection, (string) $proposal->ml_item_id),
                ['type' => 'pause_ad', 'ml_item_id' => $proposal->ml_item_id],
            ),
            'pause_campaign' => $this->campaignAction($connection, $payload, 'pause'),
            'activate_campaign' => $this->campaignAction($connection, $payload, 'activate'),
            'set_roas_target' => $this->setRoasTarget($connection, $payload),
            'set_budget' => $this->setBudget($connection, $payload),
            default => ['ok' => false, 'error' => 'Acción no ejecutable vía API: '.$proposal->action_type],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string, undo?: array<string, mixed>}
     */
    private function campaignAction(Connection $connection, array $payload, string $mode): array
    {
        $campaign = $this->findCampaign($connection, $payload);
        if ($campaign === null) {
            return ['ok' => false, 'error' => 'Campaña no encontrada.'];
        }

        $result = $mode === 'pause'
            ? $this->writeClient->pauseCampaign($connection, $campaign)
            : $this->writeClient->activateCampaign($connection, $campaign);

        return $this->withUndo($result, [
            'type' => $mode === 'pause' ? 'activate_campaign' : 'pause_campaign',
            'ad_campaign_id' => $campaign->id,
            'external_campaign_id' => $campaign->external_campaign_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string, undo?: array<string, mixed>}
     */
    private function setRoasTarget(Connection $connection, array $payload): array
    {
        $campaign = $this->findCampaign($connection, $payload);
        if ($campaign === null) {
            return ['ok' => false, 'error' => 'Campaña no encontrada.'];
        }

        $target = (float) ($payload['roas_target'] ?? 0);
        if ($target < 1 || $target > 35) {
            return ['ok' => false, 'error' => 'roas_target fuera de rango (1–35).'];
        }

        $previous = $campaign->meta['roas_target'] ?? null;
        $result = $this->writeClient->updateCampaign($connection, $campaign, ['roas_target' => $target]);

        return $this->withUndo($result, [
            'type' => 'set_roas_target',
            'ad_campaign_id' => $campaign->id,
            'roas_target' => $previous,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string, undo?: array<string, mixed>}
     */
    private function setBudget(Connection $connection, array $payload): array
    {
        $campaign = $this->findCampaign($connection, $payload);
        if ($campaign === null) {
            return ['ok' => false, 'error' => 'Campaña no encontrada.'];
        }

        $current = (float) ($campaign->meta['budget'] ?? $campaign->meta['daily_budget'] ?? 0);
        $delta = (float) ($payload['delta_pct'] ?? 0.1);
        if ($current <= 0) {
            return ['ok' => false, 'error' => 'No hay presupuesto actual conocido en meta; ajustá manualmente en ML.'];
        }

        $next = round($current * (1 + $delta), 2);
        $result = $this->writeClient->updateCampaign($connection, $campaign, ['daily_budget' => $next]);

        return $this->withUndo($result, [
            'type' => 'set_budget_absolute',
            'ad_campaign_id' => $campaign->id,
            'daily_budget' => $current,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findCampaign(Connection $connection, array $payload): ?AdCampaign
    {
        if (! empty($payload['ad_campaign_id'])) {
            return AdCampaign::query()
                ->where('connection_id', $connection->id)
                ->where('id', (int) $payload['ad_campaign_id'])
                ->first();
        }

        if (! empty($payload['external_campaign_id'])) {
            return AdCampaign::query()
                ->where('connection_id', $connection->id)
                ->where('external_campaign_id', (string) $payload['external_campaign_id'])
                ->first();
        }

        return null;
    }

    /**
     * @param  array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string}  $result
     * @param  array<string, mixed>  $undo
     * @return array{ok: bool, body?: array<string, mixed>|null, path?: string|null, error?: string, undo?: array<string, mixed>}
     */
    private function withUndo(array $result, array $undo): array
    {
        if ($result['ok'] ?? false) {
            $result['undo'] = $undo;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $undo
     * @return array{ok: bool, error?: string}
     */
    private function applyUndoPayload(Connection $connection, AdActionExecution $execution, array $undo): array
    {
        $type = (string) ($undo['type'] ?? '');

        return match ($type) {
            'activate_ad' => $this->writeClient->activateAd($connection, (string) ($undo['ml_item_id'] ?? $execution->ml_item_id)),
            'pause_ad' => $this->writeClient->pauseAd($connection, (string) ($undo['ml_item_id'] ?? $execution->ml_item_id)),
            'activate_campaign', 'pause_campaign' => $this->campaignAction(
                $connection,
                $undo,
                $type === 'pause_campaign' ? 'pause' : 'activate',
            ),
            'set_roas_target' => $this->setRoasTarget($connection, $undo),
            'set_budget_absolute' => (function () use ($connection, $undo) {
                $campaign = $this->findCampaign($connection, $undo);
                if ($campaign === null) {
                    return ['ok' => false, 'error' => 'Campaña no encontrada para undo de presupuesto.'];
                }

                return $this->writeClient->updateCampaign(
                    $connection,
                    $campaign,
                    ['daily_budget' => (float) ($undo['daily_budget'] ?? 0)],
                );
            })(),
            default => ['ok' => false, 'error' => 'Undo no soportado.'],
        };
    }
}
