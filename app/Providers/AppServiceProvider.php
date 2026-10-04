<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        \App\Models\MetaPost::class => \App\Policies\MetaPostPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fechas relativas en español («hace 17 minutos») en todo el panel
        \Carbon\Carbon::setLocale('es');
        //
    }
}
