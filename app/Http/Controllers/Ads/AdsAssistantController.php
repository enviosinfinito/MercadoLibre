<?php

namespace App\Http\Controllers\Ads;

use App\Domain\Ads\MercadoLibre\ProductAdsWriteClient;
use App\Domain\Ads\Services\AdsActionExecutor;
use App\Domain\Ads\Services\AdsCampaignWizard;
use App\Domain\Ads\Services\AdsEntityDetailQuery;
use App\Domain\Ads\Services\AdsProfitabilityService;
use App\Domain\Ads\Services\AdsRulePresetInstaller;
use App\Domain\Ads\Services\AdsRulesEvaluator;
use App\Domain\Shared\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AdActionExecution;
use App\Models\AdActionProposal;
use App\Models\AdAdvertiser;
use App\Models\AdRule;
use App\Models\AdWorkspaceSetting;
use App\Models\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdsAssistantController extends Controller
{
    public function __construct(
        private readonly AdsProfitabilityService $profitability,
        private readonly AdsRulePresetInstaller $presetInstaller,
        private readonly AdsRulesEvaluator $evaluator,
        private readonly AdsActionExecutor $executor,
        private readonly AdsCampaignWizard $wizard,
        private readonly ProductAdsWriteClient $writeClient,
        private readonly AdsEntityDetailQuery $entityDetailQuery,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $lookbackDays = (int) $request->input('days', config('ads.evaluate_lookback_days', 14));
        $lookbackDays = max(7, min(90, $lookbackDays));

        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        $scorecard = $this->profitability->scorecard($workspaceId, $lookbackDays);

        $proposals = AdActionProposal::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('estimated_impact_amount')
            ->orderBy('id')
            ->limit(40)
            ->get()
            ->sortBy(fn (AdActionProposal $p) => match ($p->priority) {
                'high' => 0,
                'medium' => 1,
                default => 2,
            })
            ->take(25)
            ->values()
            ->map(fn (AdActionProposal $p) => $this->serializeProposal($p))
            ->all();

        $rules = AdRule::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('priority')
            ->get()
            ->map(fn (AdRule $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'description' => $r->description,
                'enabled' => $r->enabled,
                'auto_execute' => $r->auto_execute,
                'priority' => $r->priority,
                'lookback_days' => $r->lookback_days,
                'action_type' => $r->action_json['type'] ?? null,
            ])
            ->values()
            ->all();

        $history = AdActionExecution::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (AdActionExecution $e) => [
                'id' => $e->id,
                'status' => $e->status,
                'actor' => $e->actor,
                'action_type' => $e->action_type,
                'entity_type' => $e->entity_type,
                'entity_id' => $e->entity_id,
                'ml_item_id' => $e->ml_item_id,
                'connection_id' => $e->connection_id,
                'reason' => $e->reason,
                'wrote_to_ml' => $e->wrote_to_ml,
                'error' => $e->error_redacted,
                'created_at' => optional($e->created_at)?->toIso8601String(),
                'undone_at' => optional($e->undone_at)?->toIso8601String(),
                'can_undo' => $e->status === 'executed' || $e->status === 'simulated',
            ])
            ->values()
            ->all();

        $connections = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'mercadolibre')
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'external_user_id', 'site_id', 'color', 'provider'])
            ->map(fn (Connection $c) => [
                'id' => $c->id,
                'label' => $c->display_name ?: $c->external_user_id,
                'display_name' => $c->display_name,
                'external_user_id' => $c->external_user_id,
                'site_id' => $c->site_id,
                'color' => $c->color,
                'provider' => $c->provider,
            ])
            ->values()
            ->all();

        $connectionsById = collect($connections)->keyBy('id');
        $attachConnection = static function (?int $connectionId) use ($connectionsById): ?array {
            $conn = $connectionsById->get((int) $connectionId);
            if (! $conn) {
                return null;
            }

            return [
                'id' => $conn['id'],
                'display_name' => $conn['display_name'] ?: $conn['label'],
                'external_user_id' => $conn['external_user_id'],
                'color' => $conn['color'],
                'provider' => $conn['provider'] ?? 'mercadolibre',
            ];
        };

        $scorecard['scorecard'] = collect($scorecard['scorecard'] ?? [])
            ->map(function (array $row) use ($attachConnection) {
                $row['connection'] = $attachConnection(isset($row['connection_id']) ? (int) $row['connection_id'] : null);

                return $row;
            })
            ->values()
            ->all();

        $proposals = collect($proposals)
            ->map(function (array $p) use ($attachConnection) {
                $p['connection'] = $attachConnection(isset($p['connection_id']) ? (int) $p['connection_id'] : null);

                return $p;
            })
            ->values()
            ->all();

        $history = collect($history)
            ->map(function (array $h) use ($attachConnection) {
                $h['connection'] = $attachConnection(isset($h['connection_id']) ? (int) $h['connection_id'] : null);

                return $h;
            })
            ->values()
            ->all();

        $advertisers = AdAdvertiser::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'connection_id', 'name', 'external_advertiser_id'])
            ->map(fn (AdAdvertiser $a) => [
                'id' => $a->id,
                'connection_id' => $a->connection_id,
                'label' => $a->name ?: $a->external_advertiser_id,
            ])
            ->values()
            ->all();

        $wizardPreview = $this->wizard->preview($workspaceId);

        return Inertia::render('Ads/Assistant', [
            'settings' => [
                'autopilot_mode' => $settings->autopilot_mode,
                'active_preset' => $settings->active_preset,
                'kill_switch' => $settings->kill_switch,
                'write_enabled' => $settings->write_enabled,
                'write_failures' => $settings->write_failures,
            ],
            'presets' => collect(config('ads.presets', []))->map(
                fn ($meta, $key) => [
                    'key' => $key,
                    'label' => $meta['label'] ?? $key,
                    'description' => $meta['description'] ?? '',
                ],
            )->values()->all(),
            'scorecard' => $scorecard,
            'proposals' => $proposals,
            'rules' => $rules,
            'history' => $history,
            'connections' => $connections,
            'advertisers' => $advertisers,
            'wizard' => $wizardPreview,
            'lookback_days' => $lookbackDays,
            'lookbackOptions' => [
                ['value' => 7, 'label' => '7 días'],
                ['value' => 14, 'label' => '14 días'],
                ['value' => 30, 'label' => '30 días'],
                ['value' => 60, 'label' => '60 días'],
                ['value' => 90, 'label' => '90 días'],
            ],
            'writeEndpointsDoc' => [
                'note' => 'Ver docs/ads-write-endpoints.md. Sin permiso Publicidad write, el asistente solo sugiere.',
            ],
        ]);
    }

    public function itemDetail(Request $request, string $mlItemId): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $mlItemId = trim($mlItemId);
        abort_unless($mlItemId !== '', 404);

        $lookbackDays = (int) $request->input('days', config('ads.evaluate_lookback_days', 14));
        $lookbackDays = max(7, min(90, $lookbackDays));
        $connectionId = $request->filled('connection_id') ? (int) $request->input('connection_id') : null;

        return response()->json(
            $this->entityDetailQuery->forItem($workspaceId, $mlItemId, $connectionId, $lookbackDays)
        );
    }

    public function itemSales(Request $request, string $mlItemId): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $mlItemId = trim($mlItemId);
        abort_unless($mlItemId !== '', 404);

        $lookbackDays = (int) $request->input('days', config('ads.evaluate_lookback_days', 14));
        $lookbackDays = max(7, min(90, $lookbackDays));
        $connectionId = $request->filled('connection_id') ? (int) $request->input('connection_id') : null;

        return response()->json(
            $this->entityDetailQuery->attributedSales($workspaceId, $mlItemId, $connectionId, $lookbackDays)
        );
    }

    public function syncCoverageOrders(Request $request, string $mlItemId): JsonResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $mlItemId = trim($mlItemId);
        abort_unless($mlItemId !== '', 404);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'days' => ['nullable', 'integer', 'min:7', 'max:90'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', (int) $data['connection_id'])
            ->firstOrFail();

        if (! empty($data['date_from']) && ! empty($data['date_to'])) {
            $from = $data['date_from'];
            $to = $data['date_to'];
        } else {
            $days = max(7, min(90, (int) ($data['days'] ?? config('ads.evaluate_lookback_days', 14))));
            $to = now()->toDateString();
            $from = now()->startOfDay()->subDays($days - 1)->toDateString();
        }

        $result = app(\App\Domain\Ads\Actions\SyncAdsPeriodOrders::class)->execute(
            $workspaceId,
            (int) $connection->id,
            $from,
            $to,
        );

        return response()->json([
            'ok' => true,
            'ml_item_id' => $mlItemId,
            'message' => 'Sync de órdenes del periodo encolado. Al terminar se re-atribuye el gasto ads.',
            ...$result,
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $data = $request->validate([
            'preset' => ['required', 'in:protect_profit,balanced,scale'],
            'autopilot_mode' => ['nullable', 'in:shadow,approve,auto'],
        ]);

        $this->presetInstaller->install($workspaceId, $data['preset']);

        if (! empty($data['autopilot_mode'])) {
            AdWorkspaceSetting::forWorkspace($workspaceId)->forceFill([
                'autopilot_mode' => $data['autopilot_mode'],
            ])->save();
        }

        $this->evaluator->execute($workspaceId);

        return redirect()->route('ads.assistant')->with('success', 'Preset instalado. Revisá la cola «Qué hacer hoy».');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $data = $request->validate([
            'autopilot_mode' => ['sometimes', 'in:shadow,approve,auto'],
            'kill_switch' => ['sometimes', 'boolean'],
            'write_enabled' => ['sometimes', 'boolean'],
        ]);

        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        $settings->forceFill($data)->save();

        return back()->with('success', 'Configuración del asistente actualizada.');
    }

    public function evaluate(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $result = $this->evaluator->execute($workspaceId);

        return back()->with(
            'success',
            "Evaluación lista: {$result['proposals_created']} propuestas nuevas (modo {$result['mode']}).",
        );
    }

    public function updateRule(Request $request, AdRule $rule): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0 && (int) $rule->workspace_id === $workspaceId, 404);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'auto_execute' => ['sometimes', 'boolean'],
        ]);

        $rule->forceFill($data)->save();

        return back()->with('success', 'Regla actualizada.');
    }

    public function approveProposal(Request $request, AdActionProposal $proposal): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0 && (int) $proposal->workspace_id === $workspaceId, 404);
        abort_unless(in_array($proposal->status, ['pending', 'approved'], true), 422);

        $execute = $request->boolean('execute', true);
        $proposal->forceFill(['status' => 'approved'])->save();

        if ($execute) {
            $out = $this->executor->executeProposal($proposal, $request->user(), forceWrite: $request->boolean('force_write'));
            return back()->with('success', $out['message']);
        }

        return back()->with('success', 'Propuesta aprobada (pendiente de ejecución).');
    }

    public function rejectProposal(AdActionProposal $proposal): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0 && (int) $proposal->workspace_id === $workspaceId, 404);

        $proposal->forceFill(['status' => 'rejected'])->save();

        return back()->with('success', 'Propuesta descartada.');
    }

    public function approveBulk(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $data = $request->validate([
            'proposal_ids' => ['required', 'array', 'min:1'],
            'proposal_ids.*' => ['integer'],
            'force_write' => ['sometimes', 'boolean'],
        ]);

        $proposals = AdActionProposal::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $data['proposal_ids'])
            ->whereIn('status', ['pending', 'approved'])
            ->get();

        $ok = 0;
        foreach ($proposals as $proposal) {
            $proposal->forceFill(['status' => 'approved'])->save();
            $this->executor->executeProposal(
                $proposal,
                $request->user(),
                forceWrite: (bool) ($data['force_write'] ?? false),
            );
            $ok++;
        }

        return back()->with('success', "Se procesaron {$ok} acciones.");
    }

    public function undoExecution(Request $request, AdActionExecution $execution): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0 && (int) $execution->workspace_id === $workspaceId, 404);

        $out = $this->executor->undo($execution, $request->user());

        return back()->with($out['status'] === 'failed' ? 'error' : 'success', $out['message']);
    }

    public function probeWrite(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
        ]);

        $connection = Connection::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $data['connection_id'])
            ->firstOrFail();

        $probe = $this->writeClient->probeWriteAccess($connection);
        $settings = AdWorkspaceSetting::forWorkspace($workspaceId);
        if ($probe['writable']) {
            $settings->forceFill(['write_enabled' => true, 'write_failures' => 0])->save();
        }

        return back()->with(
            $probe['writable'] ? 'success' : 'error',
            $probe['message'],
        );
    }

    public function createCampaign(Request $request): RedirectResponse
    {
        $workspaceId = (int) TenantContext::id();
        abort_unless($workspaceId > 0, 403);

        $data = $request->validate([
            'connection_id' => ['required', 'integer'],
            'advertiser_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'daily_budget' => ['required', 'numeric', 'min:1'],
            'roas_target' => ['nullable', 'numeric', 'min:1', 'max:35'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['string'],
            'strategy' => ['nullable', 'in:PROFITABILITY,INCREASE,VISIBILITY'],
        ]);

        $out = $this->wizard->create($workspaceId, $data, $request->user());

        return back()->with($out['ok'] ? 'success' : 'error', $out['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProposal(AdActionProposal $p): array
    {
        return [
            'id' => $p->id,
            'status' => $p->status,
            'title' => $p->title,
            'reason' => $p->reason,
            'priority' => $p->priority,
            'action_type' => $p->action_type,
            'entity_type' => $p->entity_type,
            'entity_id' => $p->entity_id,
            'ml_item_id' => $p->ml_item_id,
            'connection_id' => $p->connection_id,
            'estimated_impact_amount' => $p->estimated_impact_amount !== null
                ? (float) $p->estimated_impact_amount
                : null,
            'metrics_snapshot' => $p->metrics_snapshot,
            'action_payload' => $p->action_payload,
            'created_at' => optional($p->created_at)?->toIso8601String(),
        ];
    }
}
