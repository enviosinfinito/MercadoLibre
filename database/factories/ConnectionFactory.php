<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Workspace;
use App\Support\ConnectionColorPalette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    protected $model = Connection::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => 'mercadolibre',
            'external_user_id' => (string) fake()->unique()->numerify('########'),
            'site_id' => 'MLM',
            'color' => fake()->randomElement(ConnectionColorPalette::COLORS),
            'status' => 'active',
            'token_generation' => 1,
            'freshness_status' => 'fresh',
            'last_synced_at' => now(),
            'last_error_redacted' => null,
            'needs_reauthorization' => false,
        ];
    }
}
