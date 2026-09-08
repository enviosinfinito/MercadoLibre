<?php

namespace App\Domain\Ads\Services;

use App\Models\AdRule;
use App\Models\AdRulePack;
use App\Models\AdWorkspaceSetting;
use Illuminate\Support\Facades\DB;

final class AdsRulePresetInstaller
{
    /**
     * @return array{pack_id: int, rules: int, preset: string}
     */
    public function install(int $workspaceId, string $presetKey, bool $enableAutoExecute = false): array
    {
        $presetKey = $this->normalizePreset($presetKey);
        $definitions = $this->definitions($presetKey);
        $label = (string) (config("ads.presets.{$presetKey}.label") ?? $presetKey);

        return DB::transaction(function () use ($workspaceId, $presetKey, $definitions, $label, $enableAutoExecute) {
            $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
            $settings->forceFill([
                'active_preset' => $presetKey,
            ])->save();

            // Disable previous packs for this workspace (keep history).
            AdRulePack::query()
                ->where('workspace_id', $workspaceId)
                ->update(['enabled' => false]);

            $pack = AdRulePack::query()->updateOrCreate(
                [
                    'workspace_id' => $workspaceId,
                    'preset_key' => $presetKey,
                ],
                [
                    'name' => $label,
                    'enabled' => true,
                    'meta' => ['installed_at' => now()->toIso8601String()],
                ],
            );

            $count = 0;
            foreach ($definitions as $def) {
                AdRule::query()->updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'code' => $def['code'],
                    ],
                    [
                        'ad_rule_pack_id' => $pack->id,
                        'name' => $def['name'],
                        'description' => $def['description'],
                        'lookback_days' => $def['lookback_days'],
                        'min_spend' => $def['min_spend'],
                        'min_clicks' => $def['min_clicks'],
                        'condition_json' => $def['condition_json'],
                        'action_json' => $def['action_json'],
                        'enabled' => true,
                        'auto_execute' => $enableAutoExecute && ($def['auto_execute_default'] ?? false),
                        'priority' => $def['priority'],
                    ],
                );
                $count++;
            }

            // Disable rules from other presets that are not in this set.
            $codes = array_column($definitions, 'code');
            AdRule::query()
                ->where('workspace_id', $workspaceId)
                ->whereNotIn('code', $codes)
                ->update(['enabled' => false]);

            return [
                'pack_id' => (int) $pack->id,
                'rules' => $count,
                'preset' => $presetKey,
            ];
        });
    }

    private function normalizePreset(string $presetKey): string
    {
        $key = strtolower(trim($presetKey));
        if (! in_array($key, ['protect_profit', 'balanced', 'scale'], true)) {
            return 'protect_profit';
        }

        return $key;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(string $preset): array
    {
        $minWaste = (float) config('ads.guardrails.min_spend_for_waste_pause', 50);
        $minClicks = (int) config('ads.guardrails.min_clicks_for_roas_action', 15);
        $lookback = (int) config('ads.evaluate_lookback_days', 14);

        $base = [
            [
                'code' => 'pause_zero_stock',
                'name' => 'Pausar sin stock',
                'description' => 'Si no hay unidades disponibles, pausá el anuncio para no gastar de más.',
                'lookback_days' => $lookback,
                'min_spend' => 0,
                'min_clicks' => 0,
                'condition_json' => ['type' => 'stock_eq', 'value' => 0],
                'action_json' => ['type' => 'pause_ad'],
                'priority' => 10,
                'auto_execute_default' => true,
            ],
            [
                'code' => 'pause_waste_no_sales',
                'name' => 'Pausar desperdicio sin ventas',
                'description' => "Si gastó ≥ \${$minWaste} sin ventas atribuidas en {$lookback} días, pausá el anuncio.",
                'lookback_days' => $lookback,
                'min_spend' => $minWaste,
                'min_clicks' => 0,
                'condition_json' => [
                    'type' => 'and',
                    'conditions' => [
                        ['type' => 'units_eq', 'value' => 0],
                        ['type' => 'spend_gte', 'value' => $minWaste],
                    ],
                ],
                'action_json' => ['type' => 'pause_ad'],
                'priority' => 20,
                'auto_execute_default' => true,
            ],
            [
                'code' => 'pause_low_roas',
                'name' => 'Pausar ROAS muy bajo',
                'description' => 'Si el ROAS está por debajo del 50% del objetivo (según tu margen) y hay clicks suficientes, pausá.',
                'lookback_days' => $lookback,
                'min_spend' => 0,
                'min_clicks' => $minClicks,
                'condition_json' => [
                    'type' => 'roas_lt_target_ratio',
                    'ratio' => 0.5,
                ],
                'action_json' => ['type' => 'pause_ad'],
                'priority' => 30,
                'auto_execute_default' => false,
            ],
            [
                'code' => 'raise_roas_target',
                'name' => 'Subir ROAS objetivo',
                'description' => 'Si el ROAS está flojo vs objetivo, sugerí subir el roas_target de la campaña.',
                'lookback_days' => $lookback,
                'min_spend' => 20,
                'min_clicks' => $minClicks,
                'condition_json' => [
                    'type' => 'and',
                    'conditions' => [
                        ['type' => 'roas_lt_target_ratio', 'ratio' => 0.8],
                        ['type' => 'roas_gte_target_ratio', 'ratio' => 0.5],
                    ],
                ],
                'action_json' => [
                    'type' => 'set_roas_target',
                    'mode' => 'to_suggested',
                ],
                'priority' => 40,
                'auto_execute_default' => false,
            ],
        ];

        if ($preset === 'protect_profit') {
            return $base;
        }

        $scaleUp = [
            'code' => 'boost_winners',
            'name' => 'Impulsar ganadores',
            'description' => 'Si el ROAS supera 1.5× el objetivo y hay margen, sugerí bajar roas_target o subir presupuesto 10%.',
            'lookback_days' => $lookback,
            'min_spend' => 30,
            'min_clicks' => $minClicks,
            'condition_json' => [
                'type' => 'roas_gte_target_ratio',
                'ratio' => 1.5,
            ],
            'action_json' => [
                'type' => 'set_budget',
                'delta_pct' => $preset === 'scale' ? 0.20 : 0.10,
            ],
            'priority' => 50,
            'auto_execute_default' => false,
        ];

        if ($preset === 'scale') {
            // Soften waste pause threshold for scale (still pause extreme waste).
            $base[1]['min_spend'] = $minWaste * 2;
            $base[1]['condition_json']['conditions'][1]['value'] = $minWaste * 2;
            $base[2]['enabled'] = true;
            $base[2]['auto_execute_default'] = false;
            // Only extreme ROAS pause in scale: use 0.3 ratio
            $base[2]['condition_json']['ratio'] = 0.3;
        }

        $base[] = $scaleUp;

        return $base;
    }
}
