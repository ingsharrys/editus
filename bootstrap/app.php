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
        // Formularios grandes enviados en un solo campo (límite max_input_vars de PHP)
        $middleware->appendToGroup('web', \App\Http\Middleware\FormularioEmpaquetado::class);
        // Español o inglés según lo elegido, el perfil o el navegador
        $middleware->appendToGroup('web', \App\Http\Middleware\Idioma::class);
        // Usuarios desactivados: se cierra su sesión; a los demás se les anota el último acceso
        $middleware->appendToGroup('web', \App\Http\Middleware\UsuarioActivo::class);
        // Enlace de inscripción a una campaña y certificado obligatorio de los medios inscritos
        $middleware->appendToGroup('web', \App\Http\Middleware\InscripcionCampana::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Archivos que suman más que post_max_size de PHP: en vez de la página de error, vuelve al formulario con el aviso
        // (aquí aún no hay sesión: el aviso va en la URL y lo muestran los formularios de contrato y certificado)
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            $antes = (string) $request->headers->get('referer');
            if ($request->expectsJson() || $antes === '' || parse_url($antes, PHP_URL_HOST) !== $request->getHost()) return null;
            $antes = preg_replace('/([?&])muy_pesado=1(&|$)/', '$1', $antes);
            return redirect()->to(rtrim($antes, '?&') . (str_contains(rtrim($antes, '?&'), '?') ? '&' : '?') . 'muy_pesado=1');
        });
    })->create();
