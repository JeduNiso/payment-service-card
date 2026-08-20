<?php

namespace App\Providers;

use App\Services\CyberSource\CyberSourceService;
use App\Services\Payments\LegacyUserPermissionProvider;
use App\Services\Payments\MockLegacyUserPermissionProvider;
use App\Services\Payments\PaymentCustomerSearchService;
use App\Services\Payments\PaymentPersistenceService;
use App\Services\Payments\PaymentRouterService;
use App\Services\Payments\PaymentSearchTokenService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentSessionService;
use App\Services\Payments\PaymentTokenService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentService::class, function () {
            return new PaymentService();
        });

        $this->app->singleton(PaymentSessionService::class, function () {
            return new PaymentSessionService(app(PaymentPersistenceService::class));
        });

        $this->app->singleton(PaymentPersistenceService::class, function () {
            return new PaymentPersistenceService();
        });

        $this->app->singleton(PaymentCustomerSearchService::class, function () {
            return new PaymentCustomerSearchService();
        });

        $this->app->singleton(LegacyUserPermissionProvider::class, function () {
            return new MockLegacyUserPermissionProvider();
        });

        $this->app->singleton(PaymentRouterService::class, function () {
            return new PaymentRouterService(app(LegacyUserPermissionProvider::class));
        });

        $this->app->singleton(PaymentTokenService::class, function () {
            return new PaymentTokenService(
                app(PaymentSessionService::class),
                app(PaymentRouterService::class),
                app(PaymentPersistenceService::class)
            );
        });

        $this->app->singleton(PaymentSearchTokenService::class, function () {
            return new PaymentSearchTokenService();
        });

        $this->app->singleton(CyberSourceService::class, function () {
            return new CyberSourceService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
