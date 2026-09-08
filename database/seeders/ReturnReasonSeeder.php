<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Seeder;

class ReturnReasonSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('returns.reason_groups', []) as $group => $label) {
            ReturnReason::query()->updateOrCreate(
                ['code' => (string) $group],
                [
                    'label' => (string) $label,
                    'group' => (string) $group,
                    'ml_reason_id' => null,
                ],
            );
        }
    }
}
