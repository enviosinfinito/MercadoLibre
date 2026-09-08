<?php

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Services\EvaluateSellerReputationMetrics;
use App\Domain\Integrations\Support\BuildMercadoLibreAccountProfile;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Models\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EnrichMercadoLibreConnectionProfile
{
    public function __construct(
        private readonly MercadoLibreConnector $connector,
        private readonly EvaluateSellerReputationMetrics $evaluateReputation,
        private readonly RecordConnectionReputationSnapshot $recordSnapshot,
        private readonly BuildMercadoLibreAccountProfile $buildAccountProfile,
    ) {}

    /**
     * Fetch seller profile from ML and persist display fields.
     * Failures are logged and never rethrown so connection activation stays intact.
     */
    public function execute(Connection $connection, ?string $accessToken = null): Connection
    {
        if ($connection->provider !== 'mercadolibre') {
            return $connection;
        }

        $token = $accessToken;
        if ($token === null || $token === '') {
            $credential = $connection->credential ?? $connection->credential()->first();
            if ($credential === null) {
                return $connection;
            }

            try {
                $payload = $credential->plainPayload();
            } catch (Throwable $e) {
                Log::warning('meli.profile.decrypt_failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);

                return $connection;
            }

            $token = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : null;
        }

        if ($token === null || $token === '') {
            return $connection;
        }

        try {
            $user = $this->connector->fetchUserMe($token);
        } catch (Throwable $e) {
            Log::warning('meli.profile.fetch_failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);

            return $connection;
        }

        $nickname = isset($user['nickname']) && is_string($user['nickname']) && $user['nickname'] !== ''
            ? $user['nickname']
            : null;
        $permalink = isset($user['permalink']) && is_string($user['permalink']) && $user['permalink'] !== ''
            ? $user['permalink']
            : null;
        $siteId = isset($user['site_id']) && is_string($user['site_id']) && $user['site_id'] !== ''
            ? $user['site_id']
            : null;

        $reputation = is_array($user['seller_reputation'] ?? null) ? $user['seller_reputation'] : [];
        $reputationLevel = isset($reputation['level_id']) && is_string($reputation['level_id']) && $reputation['level_id'] !== ''
            ? $reputation['level_id']
            : null;
        $powerSellerStatus = isset($reputation['power_seller_status']) && is_string($reputation['power_seller_status']) && $reputation['power_seller_status'] !== ''
            ? $reputation['power_seller_status']
            : null;

        $userId = isset($user['id']) ? (string) $user['id'] : ($connection->external_user_id ?? null);
        $avatarUrl = $this->resolveAvatarUrl($user, $userId, $token);

        $reputationMeta = $this->buildReputationMeta(
            $reputation,
            $siteId ?? $connection->site_id ?? (string) config('mercadolibre_reputation.fallback_site', 'MLM'),
        );
        $accountProfile = $this->buildAccountProfile->execute($user);

        $connection->fill([
            'display_name' => $nickname ?? $connection->display_name,
            'permalink' => $permalink ?? $connection->permalink,
            'site_id' => $siteId ?? $connection->site_id,
            'avatar_url' => $avatarUrl ?? $connection->avatar_url,
            'reputation_level' => $reputationLevel ?? $connection->reputation_level,
            'power_seller_status' => $powerSellerStatus,
            'reputation_meta' => $reputationMeta,
            'reputation_synced_at' => now(),
            'account_profile' => $accountProfile,
            'account_profile_synced_at' => now(),
        ]);

        if ($userId !== null && $userId !== '' && ($connection->external_user_id === null || $connection->external_user_id === '')) {
            $connection->external_user_id = $userId;
        }

        $connection->save();

        try {
            $this->recordSnapshot->execute($connection->fresh(), $reputationMeta);
        } catch (Throwable $e) {
            Log::warning('meli.profile.snapshot_failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $connection->fresh();
    }

    /**
     * @param  array<string, mixed>  $reputation
     * @return array<string, mixed>
     */
    private function buildReputationMeta(array $reputation, string $siteId): array
    {
        $levelId = isset($reputation['level_id']) && is_string($reputation['level_id']) && $reputation['level_id'] !== ''
            ? $reputation['level_id']
            : null;
        $powerSeller = isset($reputation['power_seller_status']) && is_string($reputation['power_seller_status']) && $reputation['power_seller_status'] !== ''
            ? $reputation['power_seller_status']
            : null;
        $realLevel = isset($reputation['real_level']) && is_string($reputation['real_level']) && $reputation['real_level'] !== ''
            ? $reputation['real_level']
            : null;
        $protectionEnd = isset($reputation['protection_end_date']) && is_string($reputation['protection_end_date']) && $reputation['protection_end_date'] !== ''
            ? $reputation['protection_end_date']
            : null;

        $transactions = is_array($reputation['transactions'] ?? null) ? $reputation['transactions'] : [];
        $metrics = is_array($reputation['metrics'] ?? null) ? $reputation['metrics'] : [];

        $evaluation = $this->evaluateReputation->execute($siteId, $metrics, $realLevel, $protectionEnd);

        return [
            'level_id' => $levelId,
            'power_seller_status' => $powerSeller,
            'real_level' => $realLevel,
            'protection_end_date' => $protectionEnd,
            'transactions' => [
                'period' => is_string($transactions['period'] ?? null) ? $transactions['period'] : null,
                'total' => isset($transactions['total']) && is_numeric($transactions['total']) ? (int) $transactions['total'] : null,
                'completed' => isset($transactions['completed']) && is_numeric($transactions['completed']) ? (int) $transactions['completed'] : null,
                'canceled' => isset($transactions['canceled']) && is_numeric($transactions['canceled']) ? (int) $transactions['canceled'] : null,
                'ratings' => [
                    'positive' => isset($transactions['ratings']['positive']) && is_numeric($transactions['ratings']['positive'])
                        ? (float) $transactions['ratings']['positive'] : null,
                    'neutral' => isset($transactions['ratings']['neutral']) && is_numeric($transactions['ratings']['neutral'])
                        ? (float) $transactions['ratings']['neutral'] : null,
                    'negative' => isset($transactions['ratings']['negative']) && is_numeric($transactions['ratings']['negative'])
                        ? (float) $transactions['ratings']['negative'] : null,
                ],
            ],
            'metrics' => $metrics,
            'evaluation' => $evaluation,
        ];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    private function resolveAvatarUrl(array $user, ?string $userId, string $token): ?string
    {
        if ($userId !== null && $userId !== '') {
            try {
                $brands = $this->connector->fetchUserBrands($userId, $token);
                foreach ($brands as $brand) {
                    $fromBrand = $this->firstUsableImageUrl($brand);
                    if ($fromBrand !== null) {
                        return $fromBrand;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('meli.profile.brands_failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $logo = $user['logo'] ?? null;

        if (is_string($logo) && $logo !== '') {
            if ($this->isHttpUrl($logo)) {
                return $logo;
            }

            return 'https://http2.mlstatic.com/storage/logos-api-admin/'.$logo.'-logo-esp.jpg';
        }

        if (is_array($logo)) {
            return $this->firstUsableImageUrl($logo);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function firstUsableImageUrl(array $payload): ?string
    {
        foreach (['picture_url', 'secure_url', 'url', 'thumbnail', 'picture', 'logo'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $this->isHttpUrl($value)) {
                return $value;
            }
            if (is_array($value)) {
                $nested = $this->firstUsableImageUrl($value);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function isHttpUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }
}
