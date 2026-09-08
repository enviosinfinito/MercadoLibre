<?php

namespace Tests\Feature;

use App\Domain\Catalog\Actions\SyncChannelListingPurchaseExperience;
use App\Domain\Catalog\Actions\UpsertChannelListing;
use App\Jobs\SyncListingPurchaseExperienceJob;
use App\Models\ChannelListing;
use App\Models\Connection;
use App\Models\EncryptedCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelListingPurchaseExperienceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function upsert_dispatches_pe_job_only_when_include_enabled(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'site_id' => 'MLM',
        ]);

        $item = [
            'id' => 'MLM123',
            'title' => 'Producto',
            'status' => 'active',
            'price' => 100,
            'available_quantity' => 2,
        ];

        app(UpsertChannelListing::class)->execute($connection, $item, [
            'price' => true,
            'stock' => true,
            'purchase_experience' => false,
        ]);

        Queue::assertNotPushed(SyncListingPurchaseExperienceJob::class);

        app(UpsertChannelListing::class)->execute($connection, $item, [
            'price' => true,
            'stock' => true,
            'purchase_experience' => true,
        ]);

        Queue::assertPushed(SyncListingPurchaseExperienceJob::class);
    }

    #[Test]
    public function sync_persists_purchase_experience_from_item_endpoint(): void
    {
        config([
            'connectors.mercadolibre.api_base_url' => 'https://api.mercadolibre.com',
        ]);

        $workspace = Workspace::factory()->create();
        $connection = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'site_id' => 'MLM',
            'status' => 'active',
        ]);

        $credential = new EncryptedCredential(['connection_id' => $connection->id]);
        $credential->setPlainPayload([
            'access_token' => 'access-live',
            'refresh_token' => 'refresh-live',
            'expires_at' => now()->addHour()->toIso8601String(),
            'user_id' => '9',
        ]);
        $credential->save();

        $listing = ChannelListing::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'provider' => 'mercadolibre',
            'external_item_id' => 'MLM999',
            'title' => 'Item PE',
            'status' => 'active',
        ]);

        Http::fake([
            'https://api.mercadolibre.com/reputation/items/MLM999/purchase_experience/integrators*' => Http::response([
                'item_id' => 'MLM999',
                'title' => ['text' => 'Experiencia de compra'],
                'subtitles' => [['order' => 0, 'text' => 'Hay un problema']],
                'actions' => [['order' => 0, 'text' => 'Modificar publicación']],
                'reputation' => ['color' => 'orange', 'text' => 'Media', 'value' => 50],
                'status' => ['id' => 'active'],
                'metrics_details' => [
                    'problems' => [
                        [
                            'key' => 'PRODUCT',
                            'cancellations' => 1,
                            'claims' => 0,
                            'level_two' => [
                                'key' => 'POOR_CONDITION',
                                'title' => ['text' => 'Estaban en mal estado'],
                            ],
                            'level_three' => [
                                'key' => 'BROKEN_PRODUCT',
                                'title' => ['text' => 'Llegó dañado'],
                                'remedy' => ['text' => 'Revisa el embalaje'],
                            ],
                        ],
                    ],
                    'distribution' => [
                        'from' => '2023-07-04T19:08:56Z',
                        'to' => '2023-11-04T19:08:56Z',
                        'level_one' => [],
                    ],
                ],
            ], 200),
        ]);

        app(SyncChannelListingPurchaseExperience::class)->execute($listing->fresh(['connection', 'variants']));

        $listing->refresh();
        $this->assertSame('orange', $listing->pe_color);
        $this->assertSame(50, $listing->pe_value);
        $this->assertSame('item', $listing->purchase_experience['source']);
        $this->assertSame('BROKEN_PRODUCT', $listing->purchase_experience['metrics_details']['problems'][0]['level_three']['key']);
        $this->assertNotNull($listing->purchase_experience_synced_at);
    }
}
