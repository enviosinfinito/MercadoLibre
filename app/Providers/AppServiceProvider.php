<?php

namespace App\Providers;

use App\Integrations\Amazon\Connector\AmazonConnector;
use App\Integrations\Contracts\ConnectorRegistry;
use App\Integrations\Ecart\Connector\EcartConnector;
use App\Integrations\MercadoLibre\Connector\MercadoLibreConnector;
use App\Models\AnalyticsDashboard;
use App\Models\AnalyticsReport;
use App\Models\User;
use App\Policies\AnalyticsDashboardPolicy;
use App\Policies\AnalyticsReportPolicy;
use App\Services\Export\Delivery\EmailExportDeliveryChannel;
use App\Services\Export\Delivery\ExportDeliveryManager;
use App\Services\Export\ExportModuleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry;
            $registry->register('mercadolibre', $app->make(MercadoLibreConnector::class));
            $registry->register('amazon', $app->make(AmazonConnector::class));
            $registry->register('ecart', $app->make(EcartConnector::class));

            return $registry;
        });

        $this->app->singleton(ExportModuleCatalog::class);
        $this->app->singleton(ExportDeliveryManager::class, function () {
            return new ExportDeliveryManager([
                new EmailExportDeliveryChannel,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        DB::prohibitDestructiveCommands(! $this->app->environment('testing'));

        Gate::policy(AnalyticsDashboard::class, AnalyticsDashboardPolicy::class);
        Gate::policy(AnalyticsReport::class, AnalyticsReportPolicy::class);

        $this->app->booted(function () {
            Gate::define('platform-admin', function (?User $user = null) {
                return (bool) ($user?->is_platform_admin);
            });

            Gate::define('viewHorizon', function (?User $user = null) {
                return (bool) ($user?->is_platform_admin);
            });

            Gate::define('viewPulse', function (?User $user = null) {
                return (bool) ($user?->is_platform_admin);
            });

            Gate::define('viewTelescope', function (?User $user = null) {
                return (bool) ($user?->is_platform_admin);
            });

            Gate::define('analytics.manage_templates', function (?User $user = null) {
                return (bool) ($user?->is_platform_admin);
            });
        });
    }
}
