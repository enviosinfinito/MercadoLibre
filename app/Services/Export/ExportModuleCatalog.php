<?php

namespace App\Services\Export;

use App\Domain\Shared\Support\TenantContext;
use App\Http\Filters\Admin\ConnectionFilterRegistry as AdminConnectionFilterRegistry;
use App\Http\Filters\Admin\UserFilterRegistry as AdminUserFilterRegistry;
use App\Http\Filters\Admin\WorkspaceFilterRegistry as AdminWorkspaceFilterRegistry;
use App\Http\Filters\Claims\ClaimFilterRegistry;
use App\Http\Filters\Inventory\FullOperationsFilterRegistry;
use App\Http\Filters\Inventory\LedgerFilterRegistry;
use App\Http\Filters\Inventory\ReceiptFilterRegistry;
use App\Http\Filters\Orders\OrderFilterRegistry;
use App\Http\Filters\Products\ProductFilterRegistry;
use App\Http\Filters\Publications\PublicationFilterRegistry;
use App\Http\Filters\Questions\QuestionFilterRegistry;
use App\Http\Filters\Stock\StockFilterRegistry;
use App\Http\Filters\SyncHttpLogs\SyncHttpLogFilterRegistry;
use App\Models\CashLedgerEntry;
use App\Models\ChannelListing;
use App\Models\ChannelListingVariant;
use App\Models\Claim;
use App\Models\Connection;
use App\Models\CostLayer;
use App\Models\DeadLetter;
use App\Models\FullStockOperation;
use App\Models\InventoryLedger;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Question;
use App\Models\Shipment;
use App\Models\SyncHttpLog;
use App\Models\User;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Export\Contracts\ExportColumnRegistryInterface;
use App\Services\Export\Contracts\ExportRowProviderInterface;
use App\Services\Export\Modules\AnalyticsQueryExportRowProvider;
use App\Services\Export\Support\FilterAwareExportRowProvider;
use App\Services\Export\Support\SimpleColumnRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ExportModuleCatalog
{
    /** @var array<string, ExportModuleDefinition>|null */
    private ?array $modules = null;

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, ExportModuleDefinition>
     */
    public function all(): array
    {
        return $this->modules ??= $this->build();
    }

    public function get(string $key): ExportModuleDefinition
    {
        $all = $this->all();
        if (! isset($all[$key])) {
            throw new InvalidArgumentException("Unknown export module [{$key}]");
        }

        return $all[$key];
    }

    public function registry(string $key): ExportColumnRegistryInterface
    {
        return $this->get($key)->registry;
    }

    public function provider(string $key): ExportRowProviderInterface
    {
        return $this->get($key)->provider;
    }

    /**
     * Filter-engine schema basenames that should have a matching export module.
     *
     * @return list<string>
     */
    public function expectedFilterSchemaModules(): array
    {
        return [
            'orders',
            'shipments',
            'claims',
            'questions',
            'sync_http_logs',
            'products',
            'stock',
            'ledger',
            'full_operations',
            'receipts',
            'publications',
            'admin_workspaces',
            'admin_users',
            'admin_connections',
        ];
    }

    /**
     * @return array<string, ExportModuleDefinition>
     */
    private function build(): array
    {
        $defs = [];

        $defs['orders'] = $this->def('orders', 'Órdenes', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'external_order_id' => 'External Order ID',
            'status' => 'Status',
            'post_sale_outcome' => 'Post Sale Outcome',
            'buyer_external_id' => 'Buyer External ID',
            'currency_code' => 'Currency Code',
            'total_amount' => 'Total Amount',
            'ordered_at' => 'Ordered At',
            'paid_at' => 'Paid At',
            'cancelled_at' => 'Cancelled At',
            'created_at' => 'Created At',
        ], Order::class, fn ($q, $f, $u) => app(OrderFilterRegistry::class)->apply($q, $f));

        $defs['shipments'] = $this->def('shipments', 'Envíos', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'order_id' => 'Order ID',
            'external_shipment_id' => 'External Shipment ID',
            'status' => 'Status',
            'carrier' => 'Carrier',
            'tracking_number' => 'Tracking Number',
            'shipped_at' => 'Shipped At',
            'delivered_at' => 'Delivered At',
            'created_at' => 'Created At',
        ], Shipment::class, function ($q, $f, $u) {
            if (! empty($f['status'])) {
                $q->where('status', $f['status']);
            }
            if (! empty($f['connection_id'])) {
                $q->where('connection_id', (int) $f['connection_id']);
            }
            $search = trim((string) ($f['q'] ?? $f['search'] ?? ''));
            if ($search !== '') {
                $q->where(function ($b) use ($search) {
                    $b->where('tracking_number', 'like', '%'.$search.'%')
                        ->orWhere('external_shipment_id', 'like', '%'.$search.'%')
                        ->orWhere('id', $search);
                });
            }

            return $q;
        });

        $defs['claims'] = $this->def('claims', 'Reclamos', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'order_id' => 'Order ID',
            'external_claim_id' => 'External Claim ID',
            'type' => 'Type',
            'stage' => 'Stage',
            'status' => 'Status',
            'reason' => 'Reason',
            'due_at' => 'Due At',
            'opened_at' => 'Opened At',
            'closed_at' => 'Closed At',
            'created_at' => 'Created At',
        ], Claim::class, fn ($q, $f, $u) => app(ClaimFilterRegistry::class)->apply($q, $f));

        $defs['questions'] = $this->def('questions', 'Preguntas', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'external_question_id' => 'External Question ID',
            'external_item_id' => 'External Item ID',
            'buyer_external_id' => 'Buyer External ID',
            'status' => 'Status',
            'question_text' => 'Question Text',
            'answer_text' => 'Answer Text',
            'asked_at' => 'Asked At',
            'answered_at' => 'Answered At',
            'created_at' => 'Created At',
        ], Question::class, fn ($q, $f, $u) => app(QuestionFilterRegistry::class)->apply($q, $f));

        $defs['sync_http_logs'] = $this->def('sync_http_logs', 'Logs de sync', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'sync_run_id' => 'Sync Run ID',
            'provider' => 'Provider',
            'direction' => 'Direction',
            'method' => 'Method',
            'endpoint_group' => 'Endpoint Group',
            'response_status' => 'Response Status',
            'latency_ms' => 'Latency Ms',
            'correlation_id' => 'Correlation ID',
            'error_redacted' => 'Error',
            'created_at' => 'Created At',
        ], SyncHttpLog::class, fn ($q, $f, $u) => app(SyncHttpLogFilterRegistry::class)->apply($q, $f));

        $defs['products'] = $this->def('products', 'Productos', [
            'id' => 'ID',
            'name' => 'Name',
            'description' => 'Description',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ], Product::class, fn ($q, $f, $u) => app(ProductFilterRegistry::class)->apply($q, $f));

        $defs['stock'] = $this->def('stock', 'Stock', [
            'id' => 'ID',
            'product_id' => 'Product ID',
            'sku' => 'SKU',
            'gtin' => 'GTIN',
            'name' => 'Name',
            'status' => 'Status',
            'base_price_amount' => 'Base Price Amount',
            'base_price_currency' => 'Base Price Currency',
            'created_at' => 'Created At',
        ], Variant::class, fn ($q, $f, $u) => app(StockFilterRegistry::class)->apply($q, $f));

        $defs['warehouses'] = $this->def('warehouses', 'Almacenes', [
            'id' => 'ID',
            'code' => 'Code',
            'name' => 'Name',
            'is_default' => 'Is Default',
            'is_active' => 'Is Active',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ], Warehouse::class);

        $defs['ledger'] = $this->def('ledger', 'Movimientos', [
            'id' => 'ID',
            'variant_id' => 'Variant ID',
            'warehouse_id' => 'Warehouse ID',
            'movement_type' => 'Movement Type',
            'quantity_delta' => 'Quantity Delta',
            'quantity_after' => 'Quantity After',
            'reference_type' => 'Reference Type',
            'reference_id' => 'Reference ID',
            'occurred_at' => 'Occurred At',
            'created_at' => 'Created At',
        ], InventoryLedger::class, fn ($q, $f, $u) => app(LedgerFilterRegistry::class)->apply(
            $q,
            $f,
            TenantContext::workspaceId(),
        ));

        $defs['full_operations'] = $this->def('full_operations', 'Movimientos Full', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'external_operation_id' => 'External Operation ID',
            'operation_type' => 'Operation Type',
            'inventory_id' => 'Inventory ID',
            'seller_product_id' => 'Seller Product ID',
            'variant_id' => 'Variant ID',
            'available_quantity_delta' => 'Available Quantity Delta',
            'not_available_quantity_delta' => 'Not Available Quantity Delta',
            'result_available' => 'Result Available',
            'result_not_available' => 'Result Not Available',
            'result_total' => 'Result Total',
            'occurred_at' => 'Occurred At',
            'created_at' => 'Created At',
        ], FullStockOperation::class, fn ($q, $f, $u) => app(FullOperationsFilterRegistry::class)->apply(
            $q,
            $f,
            TenantContext::workspaceId(),
        ));

        $defs['receipts'] = $this->def('receipts', 'Ingresos', [
            'id' => 'ID',
            'variant_id' => 'Variant ID',
            'warehouse_id' => 'Warehouse ID',
            'qty_original' => 'Qty Original',
            'qty_remaining' => 'Qty Remaining',
            'unit_cost_amount' => 'Unit Cost Amount',
            'unit_cost_currency' => 'Unit Cost Currency',
            'source_type' => 'Source Type',
            'received_at' => 'Received At',
            'created_at' => 'Created At',
        ], CostLayer::class, function ($q, $f, $u) {
            $q->where('source_type', 'receipt');

            return app(ReceiptFilterRegistry::class)->apply(
                $q,
                $f,
                TenantContext::workspaceId(),
            );
        });

        $defs['prices'] = $this->def('prices', 'Precios', [
            'id' => 'ID',
            'channel_listing_id' => 'Channel Listing ID',
            'variant_id' => 'Variant ID',
            'external_variation_id' => 'External Variation ID',
            'sku_external' => 'SKU External',
            'status' => 'Status',
            'price_amount' => 'Price Amount',
            'currency_code' => 'Currency Code',
            'markup_pct' => 'Markup Pct',
            'available_quantity' => 'Available Quantity',
            'price_synced_at' => 'Price Synced At',
        ], ChannelListingVariant::class);

        $defs['publications'] = $this->def('publications', 'Publicaciones', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'product_id' => 'Product ID',
            'provider' => 'Provider',
            'external_item_id' => 'External Item ID',
            'title' => 'Title',
            'status' => 'Status',
            'logistic_type' => 'Logistic Type',
            'permalink' => 'Permalink',
            'external_updated_at' => 'External Updated At',
            'created_at' => 'Created At',
        ], ChannelListing::class, fn ($q, $f, $u) => app(PublicationFilterRegistry::class)->apply($q, $f));

        $defs['matching'] = $this->def('matching', 'Matching', [
            'id' => 'ID',
            'channel_listing_id' => 'Channel Listing ID',
            'variant_id' => 'Variant ID',
            'external_variation_id' => 'External Variation ID',
            'sku_external' => 'SKU External',
            'status' => 'Status',
            'price_amount' => 'Price Amount',
            'currency_code' => 'Currency Code',
            'available_quantity' => 'Available Quantity',
        ], ChannelListingVariant::class, function ($q, $f, $u) {
            $q->whereNull('variant_id');
            $search = trim((string) ($f['q'] ?? ''));
            if ($search !== '') {
                $q->where(function ($b) use ($search) {
                    $b->where('sku_external', 'like', '%'.$search.'%')
                        ->orWhere('external_variation_id', 'like', '%'.$search.'%');
                });
            }

            return $q;
        });

        $defs['connections'] = $this->def('connections', 'Conexiones', [
            'id' => 'ID',
            'provider' => 'Provider',
            'external_user_id' => 'External User ID',
            'site_id' => 'Site ID',
            'display_name' => 'Display Name',
            'status' => 'Status',
            'last_synced_at' => 'Last Synced At',
            'needs_reauthorization' => 'Needs Reauthorization',
            'created_at' => 'Created At',
        ], Connection::class);

        $defs['dead_letters'] = $this->def('dead_letters', 'Dead letters', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'queue' => 'Queue',
            'job_class' => 'Job Class',
            'error_redacted' => 'Error',
            'failed_at' => 'Failed At',
            'resolved_at' => 'Resolved At',
            'created_at' => 'Created At',
        ], DeadLetter::class);

        $defs['members'] = $this->def(
            'members',
            'Miembros',
            [
                'id' => 'ID',
                'workspace_id' => 'Workspace ID',
                'user_id' => 'User ID',
                'role_name' => 'Role Name',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ],
            WorkspaceMembership::class,
            null,
            true,
        );

        $defs['admin_workspaces'] = new ExportModuleDefinition(
            key: 'admin_workspaces',
            label: 'Admin Workspaces',
            registry: new SimpleColumnRegistry([
                'id' => 'ID',
                'name' => 'Name',
                'slug' => 'Slug',
                'reporting_currency' => 'Reporting Currency',
                'default_costing_method' => 'Default Costing Method',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ]),
            provider: new FilterAwareExportRowProvider(
                new SimpleColumnRegistry([
                    'id' => 'ID',
                    'name' => 'Name',
                    'slug' => 'Slug',
                    'reporting_currency' => 'Reporting Currency',
                    'default_costing_method' => 'Default Costing Method',
                    'created_at' => 'Created At',
                    'updated_at' => 'Updated At',
                ]),
                'admin_workspaces',
                Workspace::class,
                false,
                fn ($q, $f, $u) => app(AdminWorkspaceFilterRegistry::class)->apply($q, $f),
            ),
            requiresPlatformAdmin: true,
        );

        $adminUserCols = [
            'id' => 'ID',
            'name' => 'Name',
            'email' => 'Email',
            'email_verified_at' => 'Email Verified At',
            'is_platform_admin' => 'Is Platform Admin',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
        $defs['admin_users'] = new ExportModuleDefinition(
            key: 'admin_users',
            label: 'Admin Users',
            registry: new SimpleColumnRegistry($adminUserCols),
            provider: new FilterAwareExportRowProvider(
                new SimpleColumnRegistry($adminUserCols),
                'admin_users',
                User::class,
                false,
                fn ($q, $f, $u) => app(AdminUserFilterRegistry::class)->apply($q, $f),
            ),
            requiresPlatformAdmin: true,
        );

        $adminConnCols = [
            'id' => 'ID',
            'workspace_id' => 'Workspace ID',
            'provider' => 'Provider',
            'external_user_id' => 'External User ID',
            'display_name' => 'Display Name',
            'status' => 'Status',
            'created_at' => 'Created At',
        ];
        $defs['admin_connections'] = new ExportModuleDefinition(
            key: 'admin_connections',
            label: 'Admin Connections',
            registry: new SimpleColumnRegistry($adminConnCols),
            provider: new FilterAwareExportRowProvider(
                new SimpleColumnRegistry($adminConnCols),
                'admin_connections',
                Connection::class,
                false,
                fn ($q, $f, $u) => app(AdminConnectionFilterRegistry::class)->apply($q, $f),
            ),
            requiresPlatformAdmin: true,
        );

        $analyticsRegistry = new SimpleColumnRegistry([
            'dataset' => 'Dataset',
            'note' => 'Note',
        ]);
        $defs['analytics_query'] = new ExportModuleDefinition(
            key: 'analytics_query',
            label: 'Analytics Query',
            registry: $analyticsRegistry,
            provider: new AnalyticsQueryExportRowProvider($analyticsRegistry),
        );

        $defs['cash_ledger'] = $this->def('cash_ledger', 'Caja / ledger', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'entry_type' => 'Entry Type',
            'transaction_type' => 'Transaction Type',
            'external_source_id' => 'Source ID',
            'external_order_id' => 'Order ID',
            'external_reference' => 'External Reference',
            'gross_amount' => 'Gross Amount',
            'fee_amount' => 'Fee Amount',
            'shipping_fee_amount' => 'Shipping Fee Amount',
            'tax_amount' => 'Tax Amount',
            'financing_fee_amount' => 'Financing Fee Amount',
            'net_amount' => 'Net Amount',
            'currency_code' => 'Currency',
            'occurred_at' => 'Occurred At',
            'released_at' => 'Released At',
            'is_released' => 'Is Released',
            'provenance' => 'Provenance',
            'created_at' => 'Created At',
        ], CashLedgerEntry::class, function ($q, $f, $u) {
            if (! empty($f['entry_type'])) {
                $q->where('entry_type', $f['entry_type']);
            }
            if (! empty($f['connection_id'])) {
                $q->where('connection_id', (int) $f['connection_id']);
            }

            return $q;
        });

        $defs['marketplace_payments'] = $this->def('marketplace_payments', 'Pagos marketplace', [
            'id' => 'ID',
            'connection_id' => 'Connection ID',
            'order_id' => 'Order ID',
            'external_payment_id' => 'External Payment ID',
            'status' => 'Status',
            'transaction_amount' => 'Transaction Amount',
            'marketplace_fee_amount' => 'Marketplace Fee',
            'shipping_cost_amount' => 'Shipping Cost',
            'tax_amount' => 'Tax Amount',
            'net_received_amount' => 'Net Received',
            'expected_net_amount' => 'Expected Net',
            'diff_amount' => 'Diff Amount',
            'reconciliation_status' => 'Reconciliation Status',
            'currency_code' => 'Currency',
            'paid_at' => 'Paid At',
            'money_release_at' => 'Money Release At',
            'is_released' => 'Is Released',
            'created_at' => 'Created At',
        ], MarketplacePayment::class, function ($q, $f, $u) {
            if (! empty($f['reconciliation_status'])) {
                $q->where('reconciliation_status', $f['reconciliation_status']);
            }
            if (! empty($f['connection_id'])) {
                $q->where('connection_id', (int) $f['connection_id']);
            }

            return $q;
        });

        return $defs;
    }

    /**
     * @param  array<string, string>  $columns
     * @param  class-string<Model>  $model
     * @param  (callable(Builder, array, User): Builder)|null  $filter
     */
    private function def(
        string $key,
        string $label,
        array $columns,
        string $model,
        ?callable $filter = null,
        bool $workspaceScoped = true,
    ): ExportModuleDefinition {
        $registry = new SimpleColumnRegistry($columns);

        return new ExportModuleDefinition(
            key: $key,
            label: $label,
            registry: $registry,
            provider: new FilterAwareExportRowProvider(
                $registry,
                $key,
                $model,
                $workspaceScoped,
                $filter,
            ),
        );
    }
}
