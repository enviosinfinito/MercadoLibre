<?php

namespace App\Console\Commands;

use App\Domain\Integrations\Actions\EnrichMercadoLibreConnectionProfile;
use App\Domain\Integrations\Actions\RefreshConnectionToken;
use App\Models\Connection;
use Illuminate\Console\Command;
use Throwable;

class EnrichMercadoLibreConnectionProfilesCommand extends Command
{
    protected $signature = 'connections:enrich-mercadolibre-profiles
                            {--connection= : Limit to a single connection ID}
                            {--force : Re-fetch even when display_name is already set}';

    protected $description = 'Fetch Mercado Libre seller nickname/permalink for existing connections';

    public function handle(
        EnrichMercadoLibreConnectionProfile $enrich,
        RefreshConnectionToken $refreshToken,
    ): int {
        $query = Connection::query()
            ->where('provider', 'mercadolibre')
            ->whereIn('status', ['active', 'connected'])
            ->with('credential')
            ->orderBy('id');

        if ($this->option('connection')) {
            $query->whereKey((int) $this->option('connection'));
        }

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('display_name')->orWhere('display_name', '');
            });
        }

        $enriched = 0;
        $skipped = 0;
        $failed = 0;

        $query->each(function (Connection $connection) use ($enrich, $refreshToken, &$enriched, &$skipped, &$failed) {
            if ($connection->credential === null) {
                $this->warn("Connection {$connection->id}: no credentials, skipped");
                $skipped++;

                return;
            }

            try {
                $payload = $connection->credential->plainPayload();
            } catch (Throwable $e) {
                $this->warn("Connection {$connection->id}: decrypt failed, skipped");
                $skipped++;

                return;
            }

            $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : null;
            $expiresAt = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : null;
            $tokenLikelyExpired = $expiresAt === null || now()->gte($expiresAt);

            if ($accessToken === null || $tokenLikelyExpired) {
                try {
                    $connection = $refreshToken->execute($connection);
                    $accessToken = $connection->credential?->plainPayload()['access_token'] ?? null;
                } catch (Throwable $e) {
                    if ($accessToken === null) {
                        $this->error("Connection {$connection->id}: token refresh failed — {$e->getMessage()}");
                        $failed++;

                        return;
                    }
                    // Keep current access token and try /users/me anyway when refresh fails
                    // but an access_token is still present.
                }
            }

            if (! is_string($accessToken) || $accessToken === '') {
                $this->warn("Connection {$connection->id}: missing access_token, skipped");
                $skipped++;

                return;
            }

            $before = $connection->display_name;
            $updated = $enrich->execute($connection, $accessToken);

            if (($updated->display_name === null || $updated->display_name === '') && ! $tokenLikelyExpired) {
                // Access token may be dead without expires_at; one refresh retry.
                try {
                    $connection = $refreshToken->execute($connection->fresh(['credential']));
                    $accessToken = $connection->credential?->plainPayload()['access_token'] ?? null;
                    if (is_string($accessToken) && $accessToken !== '') {
                        $updated = $enrich->execute($connection, $accessToken);
                    }
                } catch (Throwable) {
                    // fall through to failure reporting below
                }
            }

            if ($updated->display_name !== null && $updated->display_name !== '') {
                $this->line("Connection {$connection->id}: {$updated->display_name}".($updated->site_id ? " · {$updated->site_id}" : ''));
                $enriched++;
            } elseif ($before === $updated->display_name) {
                $this->warn("Connection {$connection->id}: profile fetch did not yield display_name (reauthorize if token is invalid)");
                $failed++;
            } else {
                $enriched++;
            }
        });

        $this->info("Done. Enriched: {$enriched}, skipped: {$skipped}, failed: {$failed}");

        return $failed > 0 && $enriched === 0 ? self::FAILURE : self::SUCCESS;
    }
}
