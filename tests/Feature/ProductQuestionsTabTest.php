<?php

namespace Tests\Feature;

use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Connection;
use App\Models\Product;
use App\Models\Question;
use App\Models\User;
use App\Models\Variant;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductQuestionsTabTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace, 2: Connection, 3: Connection, 4: Product}
     */
    private function seedProductWithTwoChannels(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $connectionA = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'display_name' => 'Cuenta Alpha',
            'status' => 'active',
        ]);
        $connectionB = Connection::factory()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'mercadolibre',
            'display_name' => 'Cuenta Beta',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Producto con preguntas',
        ]);
        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => 'Q-SKU-1',
        ]);

        foreach ([
            [$connectionA, 'MLM-A'],
            [$connectionB, 'MLM-B'],
        ] as [$connection, $externalId]) {
            $listing = ChannelListing::query()->create([
                'workspace_id' => $workspace->id,
                'connection_id' => $connection->id,
                'provider' => 'mercadolibre',
                'external_item_id' => $externalId,
                'product_id' => $product->id,
                'title' => 'Listing '.$externalId,
                'status' => 'active',
            ]);
            ChannelListingVariant::query()->create([
                'workspace_id' => $workspace->id,
                'channel_listing_id' => $listing->id,
                'variant_id' => $variant->id,
                'external_variation_id' => '0',
                'sku_external' => 'Q-SKU-1',
                'status' => 'active',
            ]);
        }

        Question::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionB->id,
            'external_question_id' => 'QB-1',
            'external_item_id' => 'MLM-B',
            'buyer_external_id' => '111',
            'status' => 'answered',
            'question_text' => '¿Hay stock?',
            'answer_text' => 'Sí',
            'asked_at' => now()->subDay(),
            'answered_at' => now()->subHours(20),
        ]);

        Question::query()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connectionA->id,
            'external_question_id' => 'QA-1',
            'external_item_id' => 'MLM-A',
            'buyer_external_id' => '222',
            'status' => 'unanswered',
            'question_text' => '¿Incluye envío?',
            'answer_text' => null,
            'asked_at' => now()->subHours(2),
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace, $connectionA, $connectionB, $product];
    }

    #[Test]
    public function product_slide_context_exposes_questions_tab(): void
    {
        [, , , , $product] = $this->seedProductWithTwoChannels();

        $this->getJson(route('catalog.product-slide-context', ['product_id' => $product->id]))
            ->assertOk()
            ->assertJsonPath('tabs.questions', true)
            ->assertJsonPath('product_id', $product->id);
    }

    #[Test]
    public function product_questions_are_grouped_by_channel_sorted_by_name(): void
    {
        [, , $connectionA, $connectionB, $product] = $this->seedProductWithTwoChannels();

        $response = $this->getJson(route('catalog.product-questions', [
            'product_id' => $product->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.unanswered', 1)
            ->assertJsonCount(2, 'channels');

        // Alpha before Beta by display_name
        $response->assertJsonPath('channels.0.connection.id', $connectionA->id)
            ->assertJsonPath('channels.0.connection.display_name', 'Cuenta Alpha')
            ->assertJsonPath('channels.0.questions.0.question_text', '¿Incluye envío?')
            ->assertJsonPath('channels.1.connection.id', $connectionB->id)
            ->assertJsonPath('channels.1.questions.0.question_text', '¿Hay stock?');
    }

    #[Test]
    public function product_questions_can_resolve_by_ml_item_id(): void
    {
        [, , $connectionA] = $this->seedProductWithTwoChannels();

        $this->getJson(route('catalog.product-questions', [
            'ml_item_id' => 'MLM-A',
        ]))
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('channels.0.connection.id', $connectionA->id)
            ->assertJsonPath('channels.0.questions.0.external_item_id', 'MLM-A');
    }
}
