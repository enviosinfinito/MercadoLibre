<?php

namespace App\Domain\Analytics\Catalog;

use InvalidArgumentException;

final class DatasetCatalog
{
    /** @var array<string, DatasetDefinition>|null */
    private static ?array $datasets = null;

    public static function get(string $key): DatasetDefinition
    {
        $all = self::all();
        if (! isset($all[$key])) {
            throw new InvalidArgumentException("Unknown analytics dataset [{$key}].");
        }

        return $all[$key];
    }

    /**
     * @return array<string, DatasetDefinition>
     */
    public static function all(): array
    {
        return self::$datasets ??= [
            'orders' => self::orders(),
            'order_lines' => self::orderLines(),
            'profit' => self::profit(),
            'inventory' => self::inventory(),
            'sync_health' => self::syncHealth(),
            'claims' => self::claims(),
            'questions' => self::questions(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toArray(): array
    {
        return array_values(array_map(
            fn (DatasetDefinition $d) => $d->toArray(),
            self::all(),
        ));
    }

    private static function orders(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'orders',
            label: 'Orders',
            description: 'Canonical marketplace orders',
            from: 'orders',
            workspaceColumn: 'orders.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Order ID', 'number', 'dimension', 'orders.id', ['count']),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'orders.connection_id'),
                new ColumnDefinition('status', 'Status', 'string', 'dimension', 'orders.status'),
                new ColumnDefinition('currency_code', 'Currency', 'string', 'dimension', 'orders.currency_code'),
                new ColumnDefinition('total_amount', 'Total', 'money', 'measure', 'orders.total_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('ordered_at', 'Ordered at', 'date', 'dimension', 'orders.ordered_at'),
                new ColumnDefinition('paid_at', 'Paid at', 'date', 'dimension', 'orders.paid_at'),
                new ColumnDefinition('order_count', 'Order count', 'number', 'measure', 'orders.id', ['count']),
            ],
            joins: [
                ['dataset' => 'order_lines', 'on' => [['local' => 'orders.id', 'foreign' => 'order_lines.order_id']]],
                ['dataset' => 'profit', 'on' => [['local' => 'orders.id', 'foreign' => 'profit_snapshots.order_id']]],
            ],
        );
    }

    private static function orderLines(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'order_lines',
            label: 'Order lines',
            description: 'Line items with SKU and match status',
            from: 'order_lines',
            workspaceColumn: 'order_lines.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Line ID', 'number', 'dimension', 'order_lines.id', ['count']),
                new ColumnDefinition('order_id', 'Order ID', 'number', 'dimension', 'order_lines.order_id'),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'order_lines.connection_id'),
                new ColumnDefinition('sku', 'SKU', 'string', 'dimension', 'order_lines.sku'),
                new ColumnDefinition('title', 'Title', 'string', 'dimension', 'order_lines.title'),
                new ColumnDefinition('match_status', 'Match status', 'string', 'dimension', 'order_lines.match_status'),
                new ColumnDefinition('quantity', 'Quantity', 'number', 'measure', 'order_lines.quantity', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('unit_price_amount', 'Unit price', 'money', 'measure', 'order_lines.unit_price_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('line_total_amount', 'Line total', 'money', 'measure', 'order_lines.line_total_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('variant_id', 'Variant', 'number', 'dimension', 'order_lines.variant_id'),
                new ColumnDefinition('ordered_at', 'Ordered at', 'date', 'dimension', 'orders.ordered_at'),
                new ColumnDefinition('status', 'Order status', 'string', 'dimension', 'orders.status'),
            ],
            joins: [
                ['dataset' => 'orders', 'on' => [['local' => 'order_lines.order_id', 'foreign' => 'orders.id']]],
            ],
            defaultJoins: [
                'left join orders on orders.id = order_lines.order_id',
            ],
        );
    }

    private static function profit(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'profit',
            label: 'P&L / Profit',
            description: 'Expected and realized profit snapshots',
            from: 'profit_snapshots',
            workspaceColumn: 'profit_snapshots.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Snapshot ID', 'number', 'dimension', 'profit_snapshots.id', ['count']),
                new ColumnDefinition('order_id', 'Order ID', 'number', 'dimension', 'profit_snapshots.order_id'),
                new ColumnDefinition('stage', 'Stage', 'string', 'dimension', 'profit_snapshots.stage'),
                new ColumnDefinition('revenue', 'Revenue', 'money', 'measure', 'profit_snapshots.revenue_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('fees', 'Fees', 'money', 'measure', 'profit_snapshots.fees_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('cogs', 'COGS', 'money', 'measure', 'profit_snapshots.cogs_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('profit', 'Profit', 'money', 'measure', 'profit_snapshots.profit_amount', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('currency_code', 'Currency', 'string', 'dimension', 'profit_snapshots.currency_code'),
                new ColumnDefinition('is_incomplete', 'Incomplete', 'boolean', 'dimension', 'profit_snapshots.is_incomplete'),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'orders.connection_id'),
                new ColumnDefinition('ordered_at', 'Ordered at', 'date', 'dimension', 'orders.ordered_at'),
                new ColumnDefinition('order_status', 'Order status', 'string', 'dimension', 'orders.status'),
                new ColumnDefinition('created_at', 'Snapshot at', 'date', 'dimension', 'profit_snapshots.created_at'),
            ],
            joins: [
                ['dataset' => 'orders', 'on' => [['local' => 'profit_snapshots.order_id', 'foreign' => 'orders.id']]],
            ],
            defaultJoins: [
                'left join orders on orders.id = profit_snapshots.order_id',
            ],
        );
    }

    private static function inventory(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'inventory',
            label: 'Inventory',
            description: 'Stock balances by warehouse and SKU',
            from: 'inventory_balances',
            workspaceColumn: 'inventory_balances.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Balance ID', 'number', 'dimension', 'inventory_balances.id', ['count']),
                new ColumnDefinition('warehouse_id', 'Warehouse', 'number', 'dimension', 'inventory_items.warehouse_id'),
                new ColumnDefinition('warehouse_code', 'Warehouse code', 'string', 'dimension', 'warehouses.code'),
                new ColumnDefinition('variant_id', 'Variant', 'number', 'dimension', 'inventory_items.variant_id'),
                new ColumnDefinition('sku', 'SKU', 'string', 'dimension', 'variants.sku'),
                new ColumnDefinition('product_name', 'Product', 'string', 'dimension', 'products.name'),
                new ColumnDefinition('on_hand', 'On hand', 'number', 'measure', 'inventory_balances.quantity_on_hand', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('reserved', 'Reserved', 'number', 'measure', 'inventory_balances.quantity_reserved', ['sum', 'avg', 'min', 'max']),
                new ColumnDefinition('available', 'Available', 'number', 'measure', 'inventory_balances.quantity_available', ['sum', 'avg', 'min', 'max']),
            ],
            joins: [
                ['dataset' => 'order_lines', 'on' => [['local' => 'inventory_items.variant_id', 'foreign' => 'order_lines.variant_id']]],
            ],
            defaultJoins: [
                'inner join inventory_items on inventory_items.id = inventory_balances.inventory_item_id',
                'left join warehouses on warehouses.id = inventory_items.warehouse_id',
                'left join variants on variants.id = inventory_items.variant_id',
                'left join products on products.id = variants.product_id',
            ],
        );
    }

    private static function syncHealth(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'sync_health',
            label: 'Sync health',
            description: 'Connection sync runs and freshness',
            from: 'sync_runs',
            workspaceColumn: 'sync_runs.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Run ID', 'number', 'dimension', 'sync_runs.id', ['count']),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'sync_runs.connection_id'),
                new ColumnDefinition('resource_type', 'Resource', 'string', 'dimension', 'sync_runs.resource_type'),
                new ColumnDefinition('mode', 'Mode', 'string', 'dimension', 'sync_runs.mode'),
                new ColumnDefinition('status', 'Status', 'string', 'dimension', 'sync_runs.status'),
                new ColumnDefinition('started_at', 'Started at', 'date', 'dimension', 'sync_runs.started_at'),
                new ColumnDefinition('finished_at', 'Finished at', 'date', 'dimension', 'sync_runs.finished_at'),
                new ColumnDefinition('run_count', 'Runs', 'number', 'measure', 'sync_runs.id', ['count']),
            ],
        );
    }

    private static function claims(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'claims',
            label: 'Claims',
            description: 'Post-sale claims',
            from: 'claims',
            workspaceColumn: 'claims.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Claim ID', 'number', 'dimension', 'claims.id', ['count']),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'claims.connection_id'),
                new ColumnDefinition('status', 'Status', 'string', 'dimension', 'claims.status'),
                new ColumnDefinition('reason', 'Reason', 'string', 'dimension', 'claims.reason'),
                new ColumnDefinition('created_at', 'Created at', 'date', 'dimension', 'claims.created_at'),
                new ColumnDefinition('claim_count', 'Claims', 'number', 'measure', 'claims.id', ['count']),
            ],
        );
    }

    private static function questions(): DatasetDefinition
    {
        return new DatasetDefinition(
            key: 'questions',
            label: 'Questions',
            description: 'Buyer questions',
            from: 'questions',
            workspaceColumn: 'questions.workspace_id',
            columns: [
                new ColumnDefinition('id', 'Question ID', 'number', 'dimension', 'questions.id', ['count']),
                new ColumnDefinition('connection_id', 'Connection', 'number', 'dimension', 'questions.connection_id'),
                new ColumnDefinition('status', 'Status', 'string', 'dimension', 'questions.status'),
                new ColumnDefinition('created_at', 'Created at', 'date', 'dimension', 'questions.created_at'),
                new ColumnDefinition('question_count', 'Questions', 'number', 'measure', 'questions.id', ['count']),
            ],
        );
    }
}
