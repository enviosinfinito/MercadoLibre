<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Models\Connection;
use Illuminate\Console\Command;
use Throwable;

class RefreshExpiringConnectionTokensCommand extends Command
{
    /** Proactive window: refresh tokens that expire within 30 minutes. */
    public const SKEW_SECONDS = 1800;

    protected $signature = 'connections:refresh-expiring-tokens
                            {--connection= : Limit to a single connection ID}';

    protected $description = 'Proactively refresh Mercado Libre access tokens nearing expiry';

    public function handle(EnsureFreshConnectionToken $ensureFresh): int
    {
        $query = Connection::query()
            ->where('provider', 'mercadolibre')
            ->where('status', 'active')
            ->where('needs_reauthorization', false)
            ->with('credential')
            ->orderBy('id');

        if ($this->option('connection')) {
            $query->whereKey((int) $this->option('connection'));
        }

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        $query->each(function (Connection $connection) use ($ensureFresh, &$refreshed, &$skipped, &$failed) {
            if ($connection->credential === null) {
                $this->warn("Connection {$connection->id}: no credentials, skipped");
                $skipped++;

                return;
            }

            try {
                $payload = $connection->credential->plainPayload();
            } catch (Throwable) {
                $this->warn("Connection {$connection->id}: decrypt failed, skipped");
                $skipped++;

                return;
            }

            $expiresAt = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null;
            $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : null;
            $needsAttention = $accessToken === null
                || $accessToken === ''
                || $expiresAt === null
                || now()->greaterThanOrEqualTo(
                    \Carbon\CarbonImmutable::parse($expiresAt)->subSeconds(self::SKEW_SECONDS)
                );

            if (! $needsAttention) {
                $skipped++;

                return;
            }

            $generationBefore = (int) $connection->token_generation;
            // Force when expires_at is unknown so proactive runs still renew.
            $force = $expiresAt === null || $accessToken === null || $accessToken === '';

            try {
                $ensureFresh->execute($connection, force: $force, skewSeconds: self::SKEW_SECONDS);
                $connection->refresh();
                if ((int) $connection->token_generation > $generationBefore) {
                    $this->line("Connection {$connection->id}: refreshed");
                    $refreshed++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $this->error("Connection {$connection->id}: {$e->getMessage()}");
                $failed++;
            }
        });

        $this->info("Done. Refreshed: {$refreshed}, skipped: {$skipped}, failed: {$failed}");

        return $failed > 0 && $refreshed === 0 ? self::FAILURE : self::SUCCESS;
    }
}
