<?php

namespace App\Domain\Integrations\Actions;

use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\TokenRefreshAttempt;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class EnsureFreshConnectionToken
{
    public const DEFAULT_SKEW_SECONDS = 600;

    public function __construct(
        private readonly RefreshConnectionToken $refreshConnectionToken,
    ) {}

    /**
     * Return a usable access_token, refreshing when missing, forced, or near expiry.
     *
     * @param  int  $skewSeconds  Refresh when expires_at is within this many seconds (default 10 min).
     */
    public function execute(Connection $connection, bool $force = false, int $skewSeconds = self::DEFAULT_SKEW_SECONDS): string
    {
        $connection->loadMissing('credential');

        /** @var EncryptedCredential|null $credential */
        $credential = $connection->credential;
        if ($credential === null) {
            throw new RuntimeException('Connection has no credentials; reauthorization required.');
        }

        try {
            $payload = $credential->plainPayload();
        } catch (Throwable $e) {
            throw new RuntimeException('Connection credentials could not be decrypted.', 0, $e);
        }

        $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : null;
        $expiresAt = $payload['expires_at'] ?? null;
        // Missing expires_at: keep using the token and rely on 401 retry / proactive command.
        $shouldRefresh = $force
            || $accessToken === null
            || $accessToken === ''
            || (is_string($expiresAt) && $expiresAt !== '' && $this->isExpiredOrWithinSkew($expiresAt, $skewSeconds));

        if (! $shouldRefresh) {
            return $accessToken;
        }

        $generationBefore = (int) $connection->token_generation;

        try {
            $refreshed = $this->refreshConnectionToken->execute($connection);
            $freshPayload = $refreshed->credential?->plainPayload() ?? [];
            $freshToken = is_string($freshPayload['access_token'] ?? null)
                ? $freshPayload['access_token']
                : null;

            if ($freshToken === null || $freshToken === '') {
                throw new RuntimeException('Token refresh succeeded but access_token is missing.');
            }

            $this->recordAttempt(
                $refreshed,
                status: 'success',
                tokenGeneration: (int) $refreshed->token_generation,
                error: null,
            );

            return $freshToken;
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'concurrent refresh')) {
                $raced = $connection->fresh(['credential']);
                $racedPayload = $raced?->credential?->plainPayload() ?? [];
                $racedToken = is_string($racedPayload['access_token'] ?? null)
                    ? $racedPayload['access_token']
                    : null;
                if (is_string($racedToken) && $racedToken !== '') {
                    return $racedToken;
                }
            }

            $fresh = $connection->fresh();
            $permanent = $this->isPermanentAuthFailure($e);

            if ($fresh !== null && $permanent) {
                $fresh->needs_reauthorization = true;
                $fresh->last_error_redacted = mb_substr($e->getMessage(), 0, 500);
                $fresh->save();
            }

            if ($fresh !== null) {
                $this->recordAttempt(
                    $fresh,
                    status: 'failed',
                    tokenGeneration: $generationBefore,
                    error: mb_substr($e->getMessage(), 0, 1000),
                );
            }

            throw $e;
        }
    }

    private function isExpiredOrWithinSkew(string $expiresAt, int $skewSeconds): bool
    {
        try {
            $expires = CarbonImmutable::parse($expiresAt);
        } catch (Throwable) {
            return true;
        }

        return now()->greaterThanOrEqualTo($expires->subSeconds(max(0, $skewSeconds)));
    }

    private function isPermanentAuthFailure(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'missing refresh_token')
            || str_contains($message, 'invalid_grant')
            || str_contains($message, 'invalid refresh')
            || str_contains($message, 'revoked')
            || str_contains($message, 'reauthorization required');
    }

    private function recordAttempt(
        Connection $connection,
        string $status,
        ?int $tokenGeneration,
        ?string $error,
    ): void {
        TokenRefreshAttempt::query()->create([
            'workspace_id' => $connection->workspace_id,
            'connection_id' => $connection->id,
            'status' => $status,
            'token_generation' => $tokenGeneration,
            'error_redacted' => $error,
            'attempted_at' => now(),
        ]);
    }
}
