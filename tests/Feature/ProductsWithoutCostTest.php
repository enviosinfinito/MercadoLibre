<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductsWithoutCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingMember(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        WorkspaceMembership::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)->withSession(['workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    /**
     * @return array{0: Product, 1: Variant}
     */
    private function productWithVariant(Workspace $workspace, string $sku): array
    {
        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => "Product {$sku}",
            'status' => 'active',
        ]);

        $variant = Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => 'Default',
        ]);

        return [$product, $variant];
    }

    private function addCostLayer(Workspace $workspace, Variant $variant): CostLayer
    {
        $warehouse = Warehouse::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        return CostLayer::factory()->create([
            'workspace_id' => $workspace->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'unit_cost_amount' => '0.000000',
        ]);
    }

    #[Test]
    public function without_cost_filter_returns_only_products_missing_cost_layers(): void
    {
        [, $workspace] = $this->actingMember();
        [$missing] = $this->productWithVariant($workspace, 'NO-COST');
        [, $withCostVariant] = $this->productWithVariant($workspace, 'HAS-COST');
        $this->addCostLayer($workspace, $withCostVariant);

        $this->get(route('products.index', ['without_cost' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->where('without_cost', true)
                ->where('missing_cost_count', 1)
                ->has('products.data', 1)
                ->where('products.data.0.id', $missing->id)
                ->where('products.data.0.missing_cost', true));
    }

    #[Test]
    public function index_marks_missing_cost_and_counts_workspace_products(): void
    {
        [, $workspace] = $this->actingMember();
        [$missing] = $this->productWithVariant($workspace, 'MISSING');
        [, $pricedVariant] = $this->productWithVariant($workspace, 'PRICED');
        $this->addCostLayer($workspace, $pricedVariant);

        // Product with two variants, one without cost — still missing cost.
        [$partial, $partialVariantA] = $this->productWithVariant($workspace, 'PARTIAL-A');
        Variant::factory()->create([
            'workspace_id' => $workspace->id,
            'product_id' => $partial->id,
            'sku' => 'PARTIAL-B',
        ]);
        $this->addCostLayer($workspace, $partialVariantA);

        $pricedProductId = $pricedVariant->product_id;

        $this->get(route('products.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->where('without_cost', false)
                ->where('missing_cost_count', 2)
                ->where('products.data', function ($rows) use ($missing, $partial, $pricedProductId) {
                    $byId = collect($rows)->keyBy('id');

                    return $byId[$missing->id]['missing_cost'] === true
                        && $byId[$partial->id]['missing_cost'] === true
                        && $byId[$pricedProductId]['missing_cost'] === false;
                }));

        $this->assertTrue(
            Product::query()
                ->whereKey($partial->id)
                ->withoutCost()
                ->exists(),
        );
        $this->assertFalse(
            Product::query()
                ->whereKey($pricedProductId)
                ->withoutCost()
                ->exists(),
        );
    }

    #[Test]
    public function zero_unit_cost_layer_counts_as_defined_cost(): void
    {
        [, $workspace] = $this->actingMember();
        [, $variant] = $this->productWithVariant($workspace, 'FREE');
        $this->addCostLayer($workspace, $variant);

        $this->assertSame(0, Product::countWithoutCostForWorkspace($workspace->id));

        $this->get(route('products.index', ['without_cost' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('missing_cost_count', 0)
                ->has('products.data', 0));
    }
}
