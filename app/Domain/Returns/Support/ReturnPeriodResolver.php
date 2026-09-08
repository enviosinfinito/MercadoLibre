<?php

namespace App\Domain\Returns\Support;

use Illuminate\Support\Carbon;

final class ReturnPeriodResolver
{
    /**
     * @return array{start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon, key: string, label: string}
     */
    public function resolve(?string $preset, ?string $from = null, ?string $to = null, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->timezone(config('app.timezone', 'UTC'));
        $preset = $preset ?: 'last_30_days';

        if ($preset === 'custom' && filled($from) && filled($to)) {
            $start = Carbon::parse($from, $now->timezone)->startOfDay();
            $end = Carbon::parse($to, $now->timezone)->endOfDay();
            $days = max(1, $start->diffInDays($end) + 1);
            $previousEnd = $start->copy()->subDay()->endOfDay();
            $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

            return [
                'start' => $start,
                'end' => $end,
                'previous_start' => $previousStart,
                'previous_end' => $previousEnd,
                'key' => 'custom:'.$start->toDateString().':'.$end->toDateString(),
                'label' => 'Personalizado',
            ];
        }

        return match ($preset) {
            'today' => $this->fixedWindow($now->copy()->startOfDay(), $now->copy()->endOfDay(), 'today', 'Hoy'),
            'yesterday' => $this->fixedWindow(
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
                'yesterday',
                'Ayer',
            ),
            'last_7_days' => $this->rollingDays($now, 7, 'last_7_days', 'Últimos 7 días'),
            'this_month' => $this->fixedWindow(
                $now->copy()->startOfMonth(),
                $now->copy()->endOfDay(),
                'this_month',
                'Este mes',
            ),
            'previous_month' => $this->fixedWindow(
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
                'previous_month',
                'Mes anterior',
            ),
            'last_90_days' => $this->rollingDays($now, 90, 'last_90_days', 'Últimos 90 días'),
            'year_to_date' => $this->fixedWindow(
                $now->copy()->startOfYear(),
                $now->copy()->endOfDay(),
                'year_to_date',
                'Año actual',
            ),
            default => $this->rollingDays($now, 30, 'last_30_days', 'Últimos 30 días'),
        };
    }

    /**
     * @return array{start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon, key: string, label: string}
     */
    private function rollingDays(Carbon $now, int $days, string $key, string $label): array
    {
        $end = $now->copy()->endOfDay();
        $start = $now->copy()->subDays($days - 1)->startOfDay();
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'key' => $key,
            'label' => $label,
        ];
    }

    /**
     * @return array{start: Carbon, end: Carbon, previous_start: Carbon, previous_end: Carbon, key: string, label: string}
     */
    private function fixedWindow(Carbon $start, Carbon $end, string $key, string $label): array
    {
        $days = max(1, $start->diffInDays($end) + 1);
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'key' => $key,
            'label' => $label,
        ];
    }
}
