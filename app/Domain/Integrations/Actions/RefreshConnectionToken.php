<?php

namespace App\Domain\Integrations\Actions;

use App\Integrations\Contracts\ConnectorRegistry;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RefreshConnectionToken
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    public function execute(Connection $connection): Connection
    {
        return DB::transaction(function () use ($connection) {
            /** @var Connection $locked */
            $locked = Connection::query()
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();

            $expectedGeneration = (int) $locked->token_generation;

            $credential = EncryptedCredential::query()
                ->where('connection_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            $current = json_decode(Crypt::decryptString($credential->payload), true, 512, JSON_THROW_ON_ERROR);
            $refreshToken = $current['refresh_token'] ?? null;

            if (! is_string($refreshToken) || $refreshToken === '') {
                $locked->needs_reauthorization = true;
                $locked->save();
                throw new RuntimeException('Missing refresh_token; reauthorization required.');
            }

            $connector = $this->registry->get($locked->provider);
            $result = $connector->refreshToken([
                'refresh_token' => $refreshToken,
                'access_token' => $current['access_token'] ?? null,
            ]);

            $tokens = $result->payload;

            // CAS: only persist if generation unchanged since lock.
            $updated = Connection::query()
                ->whereKey($locked->id)
                ->where('token_generation', $expectedGeneration)
                ->update([
                    'token_generation' => $expectedGeneration + 1,
                    'needs_reauthorization' => false,
                    'last_error_redacted' => null,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('Token generation CAS failed; concurrent refresh detected.');
            }

            $payload = array_merge($current, [
                'access_token' => $tokens['access_token'] ?? $current['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $refreshToken,
                'expires_in' => $tokens['expires_in'] ?? null,
                'expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])->toIso8601String()
                    : ($current['expires_at'] ?? null),
            ]);

            $credential->payload = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            $credential->save();

            return $locked->fresh(['credential']);
        });
    }
}
