<?php

namespace Database\Factories;

use App\Models\ConnectionInvite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectionInvite>
 */
class ConnectionInviteFactory extends Factory
{
    protected $model = ConnectionInvite::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => 'mercadolibre',
            'token' => Str::random(64),
            'created_by' => User::factory(),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn () => ['used_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }
}
