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
        // Español por defecto (también en consola, colas y tareas programadas, aunque el .env diga APP_LOCALE=en);
        // en la web el middleware Idioma lo cambia a inglés si toca
        $defecto = (string) config('region.idioma_defecto', 'es');
        $this->app->setLocale($defecto);
        \Carbon\Carbon::setLocale($defecto);
        // Textos en inglés por módulo: lang/en.json (general) + lang/modulos/<módulo>/en.json
        foreach (glob(lang_path('modulos/*'), GLOB_ONLYDIR) ?: [] as $carpeta) {
            $this->app['translator']->addJsonPath($carpeta);
        }
    }
}
