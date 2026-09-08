<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Integrations\Actions\EnsureFreshConnectionToken;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Models\ChannelListing;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncChannelListingPurchaseExperience
{
    public function __construct(
        private readonly MercadoLibreConnector $connector,
        private readonly EnsureFreshConnectionToken $ensureToken,
    ) {}

    public function execute(ChannelListing $listing): ChannelListing
    {
        $listing->loadMissing(['connection', 'variants']);
        $connection = $listing->connection;
        if ($connection === null || $connection->provider !== 'mercadolibre') {
            return $listing;
        }

        $itemId = (string) $listing->external_item_id;
        if ($itemId === '') {
            return $listing;
        }

        try {
            $token = $this->ensureToken->execute($connection);
        } catch (Throwable $e) {
            Log::warning('listing.pe.token_failed', [
                'listing_id' => $listing->id,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $listing;
        }

        $userProductId = $listing->variants
            ->map(fn ($v) => is_string($v->user_product_id) && $v->user_product_id !== '' ? $v->user_product_id : null)
            ->filter()
            ->first();

        $locale = $this->connector->siteIdToPurchaseExperienceLocale($connection->site_id);

        try {
            $result = $this->connector->fetchPurchaseExperience(
                $itemId,
                $token,
                $locale,
                $userProductId,
            );
        } catch (Throwable $e) {
            Log::info('listing.pe.fetch_failed', [
                'listing_id' => $listing->id,
                'item_id' => $itemId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return $listing;
        }

        $normalized = $this->normalizePayload($result['payload'], $result['source'], $result['external_id']);
        $color = is_string($normalized['reputation']['color'] ?? null) ? $normalized['reputation']['color'] : null;
        $value = isset($normalized['reputation']['value']) && is_numeric($normalized['reputation']['value'])
            ? (int) $normalized['reputation']['value']
            : null;

        $listing->forceFill([
            'purchase_experience' => $normalized,
            'purchase_experience_synced_at' => now(),
            'pe_color' => $color,
            'pe_value' => $value,
        ])->save();

        return $listing->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, string $source, string $externalId): array
    {
        $reputation = is_array($payload['reputation'] ?? null) ? $payload['reputation'] : [];
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $freeze = $payload['freeze'] ?? null;
        $metrics = is_array($payload['metrics_details'] ?? null) ? $payload['metrics_details'] : [];
        $problems = is_array($metrics['problems'] ?? null) ? $metrics['problems'] : [];
        $distribution = is_array($metrics['distribution'] ?? null) ? $metrics['distribution'] : null;

        $normalizedProblems = [];
        foreach ($problems as $problem) {
            if (! is_array($problem)) {
                continue;
            }
            $levelTwo = is_array($problem['level_two'] ?? null) ? $problem['level_two'] : [];
            $levelThree = is_array($problem['level_three'] ?? null) ? $problem['level_three'] : [];
            $remedy = is_array($levelThree['remedy'] ?? null) ? $levelThree['remedy'] : [];
            $normalizedProblems[] = [
                'key' => $problem['key'] ?? null,
                'color' => $problem['color'] ?? null,
                'quantity' => $problem['quantity'] ?? null,
                'cancellations' => $problem['cancellations'] ?? null,
                'claims' => $problem['claims'] ?? null,
                'tag' => $problem['tag'] ?? null,
                'level_two' => [
                    'key' => $levelTwo['key'] ?? null,
                    'title' => is_array($levelTwo['title'] ?? null)
                        ? ($levelTwo['title']['text'] ?? null)
                        : null,
                ],
                'level_three' => [
                    'key' => $levelThree['key'] ?? null,
                    'title' => is_array($levelThree['title'] ?? null)
                        ? ($levelThree['title']['text'] ?? null)
                        : null,
                    'remedy' => is_string($remedy['text'] ?? null) ? $remedy['text'] : null,
                ],
            ];
        }

        return [
            'source' => $source,
            'external_id' => $externalId,
            'item_id' => $payload['item_id'] ?? null,
            'reputation' => [
                'color' => $reputation['color'] ?? null,
                'text' => $reputation['text'] ?? null,
                'value' => isset($reputation['value']) && is_numeric($reputation['value'])
                    ? (int) $reputation['value']
                    : null,
            ],
            'status' => [
                'id' => $status['id'] ?? null,
            ],
            'freeze' => $freeze,
            'title' => is_array($payload['title'] ?? null) ? ($payload['title']['text'] ?? null) : null,
            'subtitles' => is_array($payload['subtitles'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($row) => is_array($row) ? ($row['text'] ?? null) : null,
                    $payload['subtitles'],
                )))
                : [],
            'actions' => is_array($payload['actions'] ?? null)
                ? array_values(array_filter(array_map(
                    static fn ($row) => is_array($row) ? ($row['text'] ?? null) : null,
                    $payload['actions'],
                )))
                : [],
            'metrics_details' => [
                'problems' => $normalizedProblems,
                'distribution' => $distribution,
            ],
        ];
    }
}
