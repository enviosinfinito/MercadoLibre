<?php

namespace App\Domain\Integrations\Actions;

use App\Models\Connection;
use App\Models\ConnectionReputationSnapshot;
use Illuminate\Support\Carbon;

final class RecordConnectionReputationSnapshot
{
    /**
     * Persist daily sample + milestone flags for the connection reputation timeline.
     *
     * @param  array<string, mixed>  $reputationMeta
     */
    public function execute(Connection $connection, array $reputationMeta, ?Carbon $now = null): ConnectionReputationSnapshot
    {
        $tz = (string) config('app.business_timezone', 'America/Mexico_City');
        $now = ($now ?? now())->copy()->timezone($tz);
        $captureDate = $now->toDateString();

        $levelId = $this->stringOrNull($reputationMeta['level_id'] ?? null);
        $powerSeller = $this->stringOrNull($reputationMeta['power_seller_status'] ?? null);
        $evaluation = is_array($reputationMeta['evaluation'] ?? null) ? $reputationMeta['evaluation'] : [];
        $worstBand = $this->stringOrNull($evaluation['worst_band'] ?? null);
        $rates = is_array($evaluation['rates'] ?? null) ? $evaluation['rates'] : [];
        $metrics = is_array($reputationMeta['metrics'] ?? null) ? $reputationMeta['metrics'] : [];
        $sales = is_array($metrics['sales'] ?? null) ? $metrics['sales'] : [];
        $salesCompleted = isset($sales['completed']) && is_numeric($sales['completed'])
            ? (int) $sales['completed']
            : null;

        $protectionEnd = $this->stringOrNull($reputationMeta['protection_end_date'] ?? null);
        $realLevel = $this->stringOrNull($reputationMeta['real_level'] ?? null);
        $isProtected = $protectionEnd !== null || $realLevel !== null;

        $previous = ConnectionReputationSnapshot::query()
            ->where('connection_id', $connection->id)
            ->where('capture_date', '!=', $captureDate)
            ->orderByDesc('capture_date')
            ->first();

        $sameDay = ConnectionReputationSnapshot::query()
            ->where('connection_id', $connection->id)
            ->where('capture_date', $captureDate)
            ->first();

        $baseline = $previous ?? (
            $sameDay !== null
                ? ConnectionReputationSnapshot::query()
                    ->where('connection_id', $connection->id)
                    ->where('capture_date', '<', $captureDate)
                    ->orderByDesc('capture_date')
                    ->first()
                : null
        );

        [$isMilestone, $kind, $label] = $this->resolveMilestone($baseline, $levelId, $powerSeller, $worstBand, $isProtected);

        // Preserve first-sample milestone if we are updating the same day's first_sample.
        if ($sameDay !== null && $sameDay->is_milestone && $sameDay->milestone_kind === 'first_sample' && ! $isMilestone) {
            $isMilestone = true;
            $kind = 'first_sample';
            $label = $sameDay->milestone_label ?: 'Primer registro';
        }

        $attrs = [
            'workspace_id' => $connection->workspace_id,
            'captured_at' => $now->clone()->utc(),
            'level_id' => $levelId,
            'power_seller_status' => $powerSeller,
            'worst_band' => $worstBand,
            'claims_rate' => $this->floatOrNull($rates['claims'] ?? null),
            'cancellations_rate' => $this->floatOrNull($rates['cancellations'] ?? null),
            'delayed_handling_rate' => $this->floatOrNull($rates['delayed_handling_time'] ?? null),
            'sales_completed' => $salesCompleted,
            'is_milestone' => $isMilestone,
            'milestone_kind' => $isMilestone ? $kind : null,
            'milestone_label' => $isMilestone ? $label : null,
            'payload' => $reputationMeta,
        ];

        if ($sameDay !== null) {
            // If same-day already had a stronger milestone, keep the first milestone kind unless a new change happened.
            if ($sameDay->is_milestone && ! $isMilestone) {
                $attrs['is_milestone'] = true;
                $attrs['milestone_kind'] = $sameDay->milestone_kind;
                $attrs['milestone_label'] = $sameDay->milestone_label;
            }
            $sameDay->fill($attrs)->save();

            return $sameDay->fresh();
        }

        return ConnectionReputationSnapshot::query()->create([
            'connection_id' => $connection->id,
            'capture_date' => $captureDate,
            ...$attrs,
        ]);
    }

    /**
     * @return array{0: bool, 1: string|null, 2: string|null}
     */
    private function resolveMilestone(
        ?ConnectionReputationSnapshot $previous,
        ?string $levelId,
        ?string $powerSeller,
        ?string $worstBand,
        bool $isProtected,
    ): array {
        if ($previous === null) {
            return [true, 'first_sample', 'Primer registro'];
        }

        $prevProtected = filled($previous->payload['protection_end_date'] ?? null)
            || filled($previous->payload['real_level'] ?? null);

        if (! $prevProtected && $isProtected) {
            return [true, 'protection_start', 'Entró en protección'];
        }
        if ($prevProtected && ! $isProtected) {
            return [true, 'protection_end', 'Terminó la protección'];
        }

        if (($previous->level_id ?? null) !== $levelId) {
            return [true, 'level_change', $this->levelChangeLabel($previous->level_id, $levelId)];
        }

        if (($previous->power_seller_status ?? null) !== $powerSeller) {
            return [true, 'medal_change', $this->medalChangeLabel($previous->power_seller_status, $powerSeller)];
        }

        if (($previous->worst_band ?? null) !== $worstBand && $worstBand !== null) {
            return [true, 'band_change', 'Cambio de banda: '.$this->bandLabel($worstBand)];
        }

        return [false, null, null];
    }

    private function levelChangeLabel(?string $from, ?string $to): string
    {
        $toLabel = $this->levelLabel($to) ?? ($to ?: 'sin color');
        $fromRank = $this->levelRank($from);
        $toRank = $this->levelRank($to);

        if ($fromRank !== null && $toRank !== null && $toRank < $fromRank) {
            return 'Subió a '.$toLabel;
        }
        if ($fromRank !== null && $toRank !== null && $toRank > $fromRank) {
            return 'Bajó a '.$toLabel;
        }

        return 'Nivel: '.$toLabel;
    }

    private function medalChangeLabel(?string $from, ?string $to): string
    {
        if ($to === null || $to === '') {
            return 'Perdió Mercado Líder';
        }
        if ($from === null || $from === '') {
            return 'Líder '.ucfirst($to);
        }

        return 'Líder '.ucfirst((string) $from).' → '.ucfirst($to);
    }

    private function levelLabel(?string $level): ?string
    {
        if ($level === null || $level === '') {
            return null;
        }

        return match ($level) {
            '5_green' => 'Verde',
            '4_light_green' => 'Verde claro',
            '3_yellow' => 'Amarillo',
            '2_orange' => 'Naranja',
            '1_red' => 'Rojo',
            default => $level,
        };
    }

    private function levelRank(?string $level): ?int
    {
        return match ($level) {
            '5_green' => 5,
            '4_light_green' => 4,
            '3_yellow' => 3,
            '2_orange' => 2,
            '1_red' => 1,
            default => null,
        };
    }

    private function bandLabel(string $band): string
    {
        return match ($band) {
            'leader' => 'Líderes',
            'green' => 'Verde',
            'yellow' => 'Amarillo',
            'orange' => 'Naranja',
            'red' => 'Rojo',
            default => $band,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
