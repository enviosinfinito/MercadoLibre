<?php

use App\Http\Controllers\Admin\AnalyticsTemplateController as AdminAnalyticsTemplateController;
use App\Http\Controllers\Admin\ConnectionController as AdminConnectionController;
use App\Http\Controllers\Admin\DiagnosticLoggingController as AdminDiagnosticLoggingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\Analytics\DashboardController as AnalyticsDashboardController;
use App\Http\Controllers\Analytics\ReportController as AnalyticsReportController;
use App\Http\Controllers\Analytics\ScheduledExportController;
use App\Http\Controllers\Analytics\ShareController as AnalyticsShareController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Catalog\MatchingController;
use App\Http\Controllers\Catalog\ProductQuestionsController;
use App\Http\Controllers\Catalog\ProductSlideContextController;
use App\Http\Controllers\ConnectController;
use App\Http\Controllers\ConnectionInviteController;
use App\Http\Controllers\ConnectionsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Export\ExportPlatformController;
use App\Http\Controllers\ExportsController;
use App\Http\Controllers\Ads\AdsAssistantController;
use App\Http\Controllers\Ads\AdsDashboardController;
use App\Http\Controllers\Catalog\ProductAdsController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\Fulfillment\ShipmentsController;
use App\Http\Controllers\Inventory\MarketplaceStockSyncController;
use App\Http\Controllers\Inventory\FullOperationsController;
use App\Http\Controllers\Inventory\FullOperationsSyncController;
use App\Http\Controllers\Inventory\LedgerController;
use App\Http\Controllers\Inventory\PurchaseOrdersController;
use App\Http\Controllers\Inventory\ReceiptsController;
use App\Http\Controllers\Inventory\StockMutationsController;
use App\Http\Controllers\Inventory\WarehousesController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\OAuth\AmazonOAuthController;
use App\Http\Controllers\OAuth\MercadoLibreOAuthController;
use App\Http\Controllers\Ops\DeadLettersController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\OutboundStockSyncController;
use App\Http\Controllers\PostSale\ClaimsController;
use App\Http\Controllers\PostSale\QuestionsController;
use App\Http\Controllers\Returns\ReturnsDashboardController;
use App\Http\Controllers\Returns\ReturnsItemsController;
use App\Http\Controllers\Returns\ReturnsProductController;
use App\Http\Controllers\PricesController;
use App\Http\Controllers\ProductImagesController;
use App\Http\Controllers\ProductSalesController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicationsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SyncHttpLogsController;
use App\Http\Controllers\Webhooks\MercadoLibreWebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceMembersController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::post('/webhooks/mercadolibre', MercadoLibreWebhookController::class)
    ->name('webhooks.mercadolibre');

// OAuth callbacks are public: ML redirects to the tunnel host, which does not share
// the localhost session cookie. Workspace comes from the encrypted `state` payload.
Route::get('/oauth/mercadolibre/callback', [MercadoLibreOAuthController::class, 'callback'])
    ->name('oauth.mercadolibre.callback');
Route::get('/oauth/amazon/callback', [AmazonOAuthController::class, 'callback'])
    ->name('oauth.amazon.callback');

// Guest marketplace connection invites (no platform login).
Route::get('/connect/done', [ConnectController::class, 'done'])->name('connect.done');
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/connect/{token}', [ConnectController::class, 'show'])->name('connect.show');
    Route::get('/connect/{token}/start', [ConnectController::class, 'start'])->name('connect.start');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified', 'tenant', 'workspace'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::middleware(['verified', 'platform.admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::redirect('/', '/admin/workspaces')->name('home');

        Route::get('/workspaces', [AdminWorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('/workspaces/create', [AdminWorkspaceController::class, 'create'])->name('workspaces.create');
        Route::post('/workspaces', [AdminWorkspaceController::class, 'store'])->name('workspaces.store');
        Route::get('/workspaces/{workspace}', [AdminWorkspaceController::class, 'show'])->name('workspaces.show');
        Route::get('/workspaces/{workspace}/edit', [AdminWorkspaceController::class, 'edit'])->name('workspaces.edit');
        Route::put('/workspaces/{workspace}', [AdminWorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/workspaces/{workspace}', [AdminWorkspaceController::class, 'destroy'])->name('workspaces.destroy');
        Route::post('/workspaces/{workspace}/restore', [AdminWorkspaceController::class, 'restore'])->name('workspaces.restore');
        Route::post('/workspaces/{workspace}/switch', [AdminWorkspaceController::class, 'switch'])->name('workspaces.switch');
        Route::post('/workspaces/{workspace}/members', [AdminWorkspaceController::class, 'inviteMember'])->name('workspaces.members.store');
        Route::put('/workspaces/{workspace}/members/{membership}', [AdminWorkspaceController::class, 'updateMember'])->name('workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{membership}', [AdminWorkspaceController::class, 'removeMember'])->name('workspaces.members.destroy');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/memberships', [AdminUserController::class, 'storeMembership'])->name('users.memberships.store');
        Route::put('/users/{user}/memberships/{membership}', [AdminUserController::class, 'updateMembership'])->name('users.memberships.update');
        Route::delete('/users/{user}/memberships/{membership}', [AdminUserController::class, 'destroyMembership'])->name('users.memberships.destroy');

        Route::get('/connections', [AdminConnectionController::class, 'index'])->name('connections.index');
        Route::get('/connections/{connection}', [AdminConnectionController::class, 'show'])->name('connections.show');
        Route::put('/connections/{connection}', [AdminConnectionController::class, 'update'])->name('connections.update');
        Route::delete('/connections/{connection}', [AdminConnectionController::class, 'destroy'])->name('connections.destroy');

        Route::get('/analytics/templates', [AdminAnalyticsTemplateController::class, 'index'])->name('analytics.templates.index');
        Route::get('/analytics/templates/create', [AdminAnalyticsTemplateController::class, 'create'])->name('analytics.templates.create');
        Route::post('/analytics/templates', [AdminAnalyticsTemplateController::class, 'store'])->name('analytics.templates.store');
        Route::get('/analytics/templates/{dashboard}/edit', [AdminAnalyticsTemplateController::class, 'edit'])->name('analytics.templates.edit');
        Route::put('/analytics/templates/{dashboard}', [AdminAnalyticsTemplateController::class, 'update'])->name('analytics.templates.update');
        Route::delete('/analytics/templates/{dashboard}', [AdminAnalyticsTemplateController::class, 'destroy'])->name('analytics.templates.destroy');

        Route::get('/diagnostic-logging', [AdminDiagnosticLoggingController::class, 'show'])->name('diagnostic-logging.show');
        Route::post('/diagnostic-logging', [AdminDiagnosticLoggingController::class, 'update'])->name('diagnostic-logging.update');
    });

    Route::middleware(['tenant', 'workspace'])->group(function () {
        Route::get('/connections', [ConnectionsController::class, 'index'])->name('connections.index');
        Route::post('/connections', [ConnectionsController::class, 'store'])->name('connections.store');
        Route::get('/connections/{connection}', [ConnectionsController::class, 'show'])->name('connections.show');
        Route::put('/connections/{connection}/sync-profiles', [ConnectionsController::class, 'updateSyncProfiles'])
            ->name('connections.sync-profiles.update');
        Route::patch('/connections/{connection}/color', [ConnectionsController::class, 'updateColor'])
            ->name('connections.color.update');
        Route::post('/connections/{connection}/sync-now', [ConnectionsController::class, 'syncNow'])
            ->name('connections.sync-now');
        Route::post('/connections/invites', [ConnectionInviteController::class, 'store'])
            ->name('connections.invites.store');
        Route::delete('/connections/invites/{invite}', [ConnectionInviteController::class, 'destroy'])
            ->name('connections.invites.destroy');
        Route::post('/connections/{connection}/sync-listings', [ConnectionsController::class, 'syncListings'])
            ->name('connections.sync-listings');
        Route::delete('/connections/{connection}', [ConnectionsController::class, 'destroy'])->name('connections.destroy');

        Route::get('/oauth/mercadolibre/redirect', [MercadoLibreOAuthController::class, 'redirect'])->name('oauth.mercadolibre.redirect');
        Route::get('/oauth/amazon/redirect', [AmazonOAuthController::class, 'redirect'])->name('oauth.amazon.redirect');

        Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
        Route::get('/orders/list-updates', [OrdersController::class, 'indexListUpdates'])
            ->name('orders.list-updates');
        Route::get('/orders/all-ids', [OrdersController::class, 'allIds'])->name('orders.all-ids');
        Route::get('/orders/filtered-sums', [OrdersController::class, 'filteredSums'])
            ->name('orders.filtered-sums');
        Route::get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/sync-now', [OrdersController::class, 'syncNow'])->name('orders.sync-now');
        Route::get('/orders/{order}/reservations', [OrdersController::class, 'reservations'])
            ->name('orders.reservations');
        Route::get('/orders/{order}/buyer', [OrdersController::class, 'buyer'])->name('orders.buyer');
        Route::get('/orders/{order}/invoice', [OrdersController::class, 'invoice'])->name('orders.invoice');
        Route::get('/orders/{order}/invoice/pdf', [OrdersController::class, 'invoicePdf'])->name('orders.invoice.pdf');
        Route::get('/orders/{order}/invoice/xml', [OrdersController::class, 'invoiceXml'])->name('orders.invoice.xml');
        Route::get('/orders/{order}/messages', [OrdersController::class, 'messages'])->name('orders.messages');
        Route::post('/orders/{order}/messages', [OrdersController::class, 'sendMessage'])->name('orders.messages.send');
        Route::get('/orders/{order}/claims/{claim}/messages', [OrdersController::class, 'claimMessages'])
            ->name('orders.claims.messages');
        Route::post('/orders/{order}/claims/{claim}/messages', [OrdersController::class, 'sendClaimMessage'])
            ->name('orders.claims.messages.send');
        Route::get('/orders/{order}/claims/{claim}/attachments/{filename}', [OrdersController::class, 'claimAttachment'])
            ->where('filename', '[A-Za-z0-9._-]+')
            ->name('orders.claims.attachments');

        Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('/stock/all-ids', [StockController::class, 'allIds'])->name('stock.all-ids');
        Route::get('/stock/filtered-sums', [StockController::class, 'filteredSums'])->name('stock.filtered-sums');
        Route::get('/stock/marketplace/{channelListingVariant}', [StockController::class, 'showMarketplace'])
            ->name('stock.marketplace.show');
        Route::get('/stock/{variant}', [StockController::class, 'show'])->name('stock.show');
        Route::post('/stock/{variant}/adjust', [StockMutationsController::class, 'adjust'])->name('stock.adjust');
        Route::post('/stock/{variant}/transfer', [StockMutationsController::class, 'transfer'])->name('stock.transfer');
        Route::post('/stock/{variant}/ship-to-full', [StockMutationsController::class, 'shipToFull'])->name('stock.ship-to-full');
        Route::post('/stock/{variant}/return-from-full', [StockMutationsController::class, 'returnFromFull'])->name('stock.return-from-full');
        Route::post('/stock/reservations/{reservation}/release', [StockMutationsController::class, 'release'])
            ->name('stock.reservations.release');
        Route::post('/stock/sync', [OutboundStockSyncController::class, 'store'])->name('stock.sync');
        Route::post('/stock/sync-marketplace', [MarketplaceStockSyncController::class, 'store'])
            ->name('stock.sync-marketplace');

        Route::get('/prices', [PricesController::class, 'index'])->name('prices.index');
        Route::put('/prices/{channelListingVariant}', [PricesController::class, 'update'])->name('prices.update');

        Route::get('/publications', [PublicationsController::class, 'index'])->name('publications.index');
        Route::get('/publications/all-ids', [PublicationsController::class, 'allIds'])->name('publications.all-ids');
        Route::get('/publications/filtered-sums', [PublicationsController::class, 'filteredSums'])
            ->name('publications.filtered-sums');
        Route::get('/publications/{channelListing}', [PublicationsController::class, 'show'])->name('publications.show');
        Route::put('/publications/{channelListing}', [PublicationsController::class, 'update'])->name('publications.update');
        Route::post('/publications/{channelListing}/sync-now', [PublicationsController::class, 'syncNow'])
            ->name('publications.sync-now');
        Route::post('/publications/bulk', [PublicationsController::class, 'bulk'])->name('publications.bulk');

        Route::get('/catalog/product-slide-context', ProductSlideContextController::class)
            ->name('catalog.product-slide-context');
        Route::get('/catalog/product-questions', ProductQuestionsController::class)
            ->name('catalog.product-questions');

        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::post('/monitoring/alerts/{alert}/acknowledge', [MonitoringController::class, 'acknowledge'])
            ->name('monitoring.alerts.acknowledge');
        Route::get('/sync-logs', [SyncHttpLogsController::class, 'index'])->name('sync-logs.index');
        Route::get('/sync-logs/list-updates', [SyncHttpLogsController::class, 'indexListUpdates'])
            ->name('sync-logs.list-updates');
        Route::get('/sync-logs/{syncHttpLog}', [SyncHttpLogsController::class, 'show'])->name('sync-logs.show');

        Route::get('/products', [ProductsController::class, 'index'])->name('products.index');
        Route::get('/products/all-ids', [ProductsController::class, 'allIds'])->name('products.all-ids');
        Route::get('/products/filtered-sums', [ProductsController::class, 'filteredSums'])
            ->name('products.filtered-sums');
        Route::get('/products/create', [ProductsController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductsController::class, 'store'])->name('products.store');
        Route::post('/products/stock-sync', [OutboundStockSyncController::class, 'store'])->name('products.stock-sync');
        Route::get('/products/{product}/edit', [ProductsController::class, 'edit'])->name('products.edit');
        Route::get('/products/{product}/sales', [ProductSalesController::class, 'show'])->name('products.sales');
        Route::get('/products/{product}/ads', [ProductAdsController::class, 'show'])->name('products.ads');
        Route::put('/products/{product}', [ProductsController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/images', [ProductImagesController::class, 'store'])->name('products.images.store');
        Route::delete('/products/{product}/images/{productImage}', [ProductImagesController::class, 'destroy'])
            ->name('products.images.destroy');
        Route::delete('/products/{product}', [ProductsController::class, 'destroy'])->name('products.destroy');
        Route::post('/products/{product}/restore', [ProductsController::class, 'restore'])->name('products.restore');

        Route::get('/inventory/warehouses', [WarehousesController::class, 'index'])->name('inventory.warehouses.index');
        Route::post('/inventory/warehouses', [WarehousesController::class, 'store'])->name('inventory.warehouses.store');
        Route::put('/inventory/warehouses/{warehouse}', [WarehousesController::class, 'update'])->name('inventory.warehouses.update');

        Route::get('/inventory/ledger', [LedgerController::class, 'index'])->name('inventory.ledger.index');

        Route::get('/inventory/full-operations', [FullOperationsController::class, 'index'])
            ->name('inventory.full-operations.index');
        Route::get('/inventory/full-operations/all-ids', [FullOperationsController::class, 'allIds'])
            ->name('inventory.full-operations.all-ids');
        Route::get('/inventory/full-operations/filtered-sums', [FullOperationsController::class, 'filteredSums'])
            ->name('inventory.full-operations.filtered-sums');
        Route::post('/inventory/full-operations/sync', [FullOperationsSyncController::class, 'store'])
            ->name('inventory.full-operations.sync');
        Route::get('/inventory/full-operations/{fullStockOperation}', [FullOperationsController::class, 'show'])
            ->name('inventory.full-operations.show');

        Route::get('/inventory/receipts', [ReceiptsController::class, 'index'])->name('inventory.receipts.index');
        Route::get('/inventory/receipts/create', [ReceiptsController::class, 'create'])->name('inventory.receipts.create');
        Route::post('/inventory/receipts', [ReceiptsController::class, 'store'])->name('inventory.receipts.store');
        Route::get('/inventory/purchase-orders/create', [PurchaseOrdersController::class, 'create'])
            ->name('inventory.purchase-orders.create');
        Route::post('/inventory/purchase-orders', [PurchaseOrdersController::class, 'store'])
            ->name('inventory.purchase-orders.store');
        Route::get('/inventory/purchase-orders/{purchaseOrder}', [PurchaseOrdersController::class, 'show'])
            ->name('inventory.purchase-orders.show');
        Route::post('/inventory/purchase-orders/{purchaseOrder}/lines/{line}/receive', [PurchaseOrdersController::class, 'receiveLine'])
            ->name('inventory.purchase-orders.receive');

        Route::get('/matching', [MatchingController::class, 'index'])->name('matching.index');
        Route::post('/matching', [MatchingController::class, 'store'])->name('matching.store');

        Route::get('/claims', [ClaimsController::class, 'index'])->name('claims.index');
        Route::get('/claims/all-ids', [ClaimsController::class, 'allIds'])->name('claims.all-ids');
        Route::get('/claims/filtered-sums', [ClaimsController::class, 'filteredSums'])->name('claims.filtered-sums');

        Route::get('/returns', [ReturnsDashboardController::class, 'index'])->name('returns.index');
        Route::get('/returns/items', [ReturnsItemsController::class, 'index'])->name('returns.items.index');
        Route::get('/returns/items/{returnCase}', [ReturnsItemsController::class, 'show'])->name('returns.items.show');
        Route::get('/returns/products/{product}', [ReturnsProductController::class, 'show'])->name('returns.products.show');
        Route::post('/returns/products/{product}/actions', [ReturnsProductController::class, 'upsertAction'])
            ->name('returns.products.actions');
        Route::get('/questions', [QuestionsController::class, 'index'])->name('questions.index');
        Route::get('/questions/all-ids', [QuestionsController::class, 'allIds'])->name('questions.all-ids');
        Route::get('/questions/filtered-sums', [QuestionsController::class, 'filteredSums'])
            ->name('questions.filtered-sums');
        Route::get('/questions/{question}', [QuestionsController::class, 'show'])->name('questions.show');
        Route::post('/questions/{question}/answer', [QuestionsController::class, 'answer'])->name('questions.answer');

        Route::get('/shipments', [ShipmentsController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/all-ids', [ShipmentsController::class, 'allIds'])->name('shipments.all-ids');
        Route::get('/shipments/filtered-sums', [ShipmentsController::class, 'filteredSums'])
            ->name('shipments.filtered-sums');
        Route::get('/shipments/{shipment}', [ShipmentsController::class, 'show'])->name('shipments.show');

        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

        Route::get('/workspaces/members', [WorkspaceMembersController::class, 'index'])->name('workspaces.members.index');
        Route::post('/workspaces/members/invite', [WorkspaceMembersController::class, 'invite'])->name('workspaces.members.invite');

        Route::get('/exports', [ExportPlatformController::class, 'hub'])->name('exports.index');
        Route::post('/exports', [ExportsController::class, 'store'])->name('exports.store'); // legacy analytics queue
        Route::post('/exports/start', [ExportPlatformController::class, 'start'])->name('exports.start');
        Route::get('/exports/columns', [ExportPlatformController::class, 'columns'])->name('exports.columns');
        Route::get('/exports/options', [ExportPlatformController::class, 'optionsForModule'])->name('exports.options');
        Route::get('/exports/status', [ExportPlatformController::class, 'status'])->name('exports.status');
        Route::get('/exports/preview', [ExportPlatformController::class, 'preview'])->name('exports.preview');
        Route::get('/exports/history', [ExportPlatformController::class, 'history'])->name('exports.history');
        Route::get('/exports/active', [ExportPlatformController::class, 'active'])->name('exports.active');
        Route::post('/exports/cancel', [ExportPlatformController::class, 'cancel'])->name('exports.cancel');
        Route::post('/exports/resume', [ExportPlatformController::class, 'resume'])->name('exports.resume');
        Route::post('/exports/regenerate', [ExportPlatformController::class, 'regenerate'])->name('exports.regenerate');
        Route::get('/exports/download/{token}', [ExportPlatformController::class, 'download'])->name('exports.download');
        Route::post('/exports/configs', [ExportPlatformController::class, 'storeConfig'])->name('exports.configs.store');
        Route::put('/exports/configs/{config}', [ExportPlatformController::class, 'updateConfig'])->name('exports.configs.update');
        Route::delete('/exports/configs/{config}', [ExportPlatformController::class, 'destroyConfig'])->name('exports.configs.destroy');
        Route::post('/exports/presets', [ExportPlatformController::class, 'storePreset'])->name('exports.presets.store');
        Route::put('/exports/presets/{preset}', [ExportPlatformController::class, 'updatePreset'])->name('exports.presets.update');
        Route::delete('/exports/presets/{preset}', [ExportPlatformController::class, 'destroyPreset'])->name('exports.presets.destroy');
        Route::post('/exports/schedules', [ExportPlatformController::class, 'storeSchedule'])->name('exports.schedules.store');
        Route::put('/exports/schedules/{scheduledExport}', [ExportPlatformController::class, 'updateSchedule'])->name('exports.schedules.update');
        Route::delete('/exports/schedules/{scheduledExport}', [ExportPlatformController::class, 'destroySchedule'])->name('exports.schedules.destroy');
        Route::post('/exports/schedules/{scheduledExport}/run-now', [ExportPlatformController::class, 'runScheduleNow'])->name('exports.schedules.run-now');

        Route::get('/ops/dead-letters', [DeadLettersController::class, 'index'])->name('ops.dead-letters.index');
        Route::post('/ops/dead-letters/{deadLetter}/replay', [DeadLettersController::class, 'replay'])->name('ops.dead-letters.replay');

        Route::get('/finance', [FinanceController::class, 'dashboard'])->name('finance.dashboard');
        Route::get('/finance/cash', [\App\Http\Controllers\Finance\CashController::class, 'index'])->name('finance.cash.index');
        Route::get('/finance/cash/entries/{entry}', [\App\Http\Controllers\Finance\CashController::class, 'showEntry'])->name('finance.cash.entries.show');
        Route::get('/finance/cash/payments/{payment}', [\App\Http\Controllers\Finance\CashController::class, 'showPayment'])->name('finance.cash.payments.show');
        Route::get('/finance/cash/release-bucket', [\App\Http\Controllers\Finance\CashController::class, 'showReleaseBucket'])->name('finance.cash.releases.show');
        Route::get('/finance/cash/orders/{order}/diff', [\App\Http\Controllers\Finance\CashController::class, 'showOrderDiff'])->name('finance.cash.orders.diff');
        Route::post('/finance/cash/fetch-order', [\App\Http\Controllers\Finance\CashController::class, 'fetchOrder'])->name('finance.cash.fetch-order');
        Route::post('/finance/cash/sync', [\App\Http\Controllers\Finance\CashController::class, 'sync'])->name('finance.cash.sync');
        Route::get('/finance/cash/sync-status', [\App\Http\Controllers\Finance\CashController::class, 'syncStatus'])->name('finance.cash.sync-status');
        Route::get('/finance/cash/report-files', [\App\Http\Controllers\Finance\CashController::class, 'reportFiles'])->name('finance.cash.report-files');
        Route::get('/finance/cash/report-files/{file}/download', [\App\Http\Controllers\Finance\CashController::class, 'downloadReportFile'])->name('finance.cash.report-files.download');
        Route::get('/finance/cash/report-files/{file}/preview', [\App\Http\Controllers\Finance\CashController::class, 'previewReportFile'])->name('finance.cash.report-files.preview');
        Route::get('/finance/cash/latest-run', [\App\Http\Controllers\Finance\CashController::class, 'latestRun'])->name('finance.cash.latest-run');
        Route::get('/ads', [AdsDashboardController::class, 'index'])->name('ads.dashboard');
        Route::get('/ads/assistant', [AdsAssistantController::class, 'index'])->name('ads.assistant');
        Route::get('/ads/assistant/items/{mlItemId}', [AdsAssistantController::class, 'itemDetail'])->name('ads.assistant.items.show');
        Route::get('/ads/assistant/items/{mlItemId}/sales', [AdsAssistantController::class, 'itemSales'])->name('ads.assistant.items.sales');
        Route::post('/ads/assistant/items/{mlItemId}/sync-orders', [AdsAssistantController::class, 'syncCoverageOrders'])->name('ads.assistant.items.sync-orders');
        Route::post('/ads/assistant/setup', [AdsAssistantController::class, 'setup'])->name('ads.assistant.setup');
        Route::put('/ads/assistant/settings', [AdsAssistantController::class, 'updateSettings'])->name('ads.assistant.settings');
        Route::post('/ads/assistant/evaluate', [AdsAssistantController::class, 'evaluate'])->name('ads.assistant.evaluate');
        Route::put('/ads/assistant/rules/{rule}', [AdsAssistantController::class, 'updateRule'])->name('ads.assistant.rules.update');
        Route::post('/ads/assistant/proposals/{proposal}/approve', [AdsAssistantController::class, 'approveProposal'])->name('ads.assistant.proposals.approve');
        Route::post('/ads/assistant/proposals/{proposal}/reject', [AdsAssistantController::class, 'rejectProposal'])->name('ads.assistant.proposals.reject');
        Route::post('/ads/assistant/proposals/approve-bulk', [AdsAssistantController::class, 'approveBulk'])->name('ads.assistant.proposals.approve-bulk');
        Route::post('/ads/assistant/executions/{execution}/undo', [AdsAssistantController::class, 'undoExecution'])->name('ads.assistant.executions.undo');
        Route::post('/ads/assistant/probe-write', [AdsAssistantController::class, 'probeWrite'])->name('ads.assistant.probe-write');
        Route::post('/ads/assistant/campaigns', [AdsAssistantController::class, 'createCampaign'])->name('ads.assistant.campaigns.create');

        Route::get('/analytics', [AnalyticsDashboardController::class, 'index'])->name('analytics.dashboards.index');
        Route::post('/analytics/dashboards', [AnalyticsDashboardController::class, 'store'])->name('analytics.dashboards.store');
        Route::get('/analytics/dashboards/{dashboard}', [AnalyticsDashboardController::class, 'show'])->name('analytics.dashboards.show');
        Route::get('/analytics/dashboards/{dashboard}/edit', [AnalyticsDashboardController::class, 'edit'])->name('analytics.dashboards.edit');
        Route::put('/analytics/dashboards/{dashboard}', [AnalyticsDashboardController::class, 'update'])->name('analytics.dashboards.update');
        Route::delete('/analytics/dashboards/{dashboard}', [AnalyticsDashboardController::class, 'destroy'])->name('analytics.dashboards.destroy');
        Route::post('/analytics/dashboards/{dashboard}/clone', [AnalyticsDashboardController::class, 'clone'])->name('analytics.dashboards.clone');
        Route::post('/analytics/dashboards/{dashboard}/shares', [AnalyticsShareController::class, 'store'])->name('analytics.dashboards.shares.store');
        Route::delete('/analytics/dashboards/{dashboard}/shares/{share}', [AnalyticsShareController::class, 'destroy'])->name('analytics.dashboards.shares.destroy');
        Route::post('/analytics/query', [AnalyticsDashboardController::class, 'query'])->name('analytics.query');
        Route::get('/analytics/catalog', [AnalyticsDashboardController::class, 'catalog'])->name('analytics.catalog');
        Route::post('/analytics/drillthrough', [AnalyticsDashboardController::class, 'drillthrough'])->name('analytics.drillthrough');

        Route::get('/analytics/explore', [AnalyticsReportController::class, 'explore'])->name('analytics.explore');
        Route::post('/analytics/reports', [AnalyticsReportController::class, 'store'])->name('analytics.reports.store');
        Route::put('/analytics/reports/{report}', [AnalyticsReportController::class, 'update'])->name('analytics.reports.update');
        Route::delete('/analytics/reports/{report}', [AnalyticsReportController::class, 'destroy'])->name('analytics.reports.destroy');
        Route::post('/analytics/reports/{report}/export', [AnalyticsReportController::class, 'export'])->name('analytics.reports.export');
        Route::post('/analytics/export-query', [AnalyticsReportController::class, 'exportQuery'])->name('analytics.export-query');

        Route::get('/analytics/schedules', [ScheduledExportController::class, 'index'])->name('analytics.schedules.index');
        Route::post('/analytics/schedules', [ScheduledExportController::class, 'store'])->name('analytics.schedules.store');
        Route::put('/analytics/schedules/{scheduledExport}', [ScheduledExportController::class, 'update'])->name('analytics.schedules.update');
        Route::delete('/analytics/schedules/{scheduledExport}', [ScheduledExportController::class, 'destroy'])->name('analytics.schedules.destroy');
    });
});

require __DIR__.'/auth.php';
