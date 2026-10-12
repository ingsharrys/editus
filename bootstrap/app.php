<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'permiso' => \App\Http\Middleware\PermisoMiddleware::class,
            'editus.token' => \App\Http\Middleware\EditusApiToken::class,
        ]);
        // Español o inglés según lo elegido, el perfil o el navegador
        $middleware->appendToGroup('web', \App\Http\Middleware\Idioma::class);
        // Usuarios desactivados: se cierra su sesión; a los demás se les anota el último acceso
        $middleware->appendToGroup('web', \App\Http\Middleware\UsuarioActivo::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
