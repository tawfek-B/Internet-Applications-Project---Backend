<?php

namespace App\Providers;

use App\Services\Inventory\FakeInventoryClient;
use App\Services\Inventory\GrpcInventoryClient;
use App\Services\Inventory\HttpInventoryClient;
use App\Services\Inventory\InventoryClientInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\ExecutableFinder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InventoryClientInterface::class, function () {
            if (config('services.inventory.driver') === 'fake') {
                return new FakeInventoryClient;
            }

            if (config('services.inventory.driver') === 'http') {
                return new HttpInventoryClient(
                    baseUrl: (string) config('services.inventory.http_url'),
                    timeoutSeconds: (int) config('services.inventory.timeout_seconds'),
                );
            }

            $nodeBinary = (string) config('services.inventory.node_binary');

            if (! str_contains($nodeBinary, DIRECTORY_SEPARATOR)) {
                $resolved = (new ExecutableFinder)->find($nodeBinary);

                if ($resolved !== null) {
                    $nodeBinary = $resolved;
                }
            }

            return new GrpcInventoryClient(
                nodeBinary: $nodeBinary,
                clientScript: config('services.inventory.client_script'),
                grpcHost: config('services.inventory.host'),
                timeoutSeconds: (int) config('services.inventory.timeout_seconds'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.$request->ip());
        });
    }
}
