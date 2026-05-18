<?php

namespace App\Providers;

use App\Contracts\PixGatewayInterface;
use App\Services\Gateway\MockPixGateway;
use Illuminate\Support\ServiceProvider;

class PixGatewayServiceProvider extends ServiceProvider
{
    /**
     * Register the PIX gateway binding.
     *
     * Resolves PixGatewayInterface to the implementation
     * specified by config('pix.gateway.driver').
     */
    public function register(): void
    {
        $this->app->singleton(PixGatewayInterface::class, function ($app) {
            $driver = config('pix.gateway.driver', 'mock');

            return match ($driver) {
                'mock' => new MockPixGateway,
                default => new MockPixGateway,
            };
        });
    }
}
