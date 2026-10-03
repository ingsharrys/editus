<?php

use App\Http\Controllers\Admin\EditorAppController;
use App\Http\Controllers\Auth\FacebookAuthController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\Meta\FacebookPageController;
use App\Http\Controllers\MetaPostController;
use App\Http\Controllers\MyPostsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => view('welcome'));

Route::view('/privacy', 'privacy')->name('privacy');

// Escena de las transmisiones en vivo (la carga el egress de LiveKit con su propio token)
// Escena que compone el egress (HTML estático en infra/en-vivo/escena; también se puede servir desde el VPS, ver LIVEKIT_ESCENA_URL)
Route::get('/en-vivo/escena', fn() => response(file_get_contents(base_path('infra/en-vivo/escena/index.html')), 200, ['Content-Type' => 'text/html; charset=utf-8']))->name('en-vivo.escena');
// Invitados a una transmisión (cámara remota desde el navegador, con código de invitación)
Route::get('/en-vivo/invitado/{codigo}', [\App\Http\Controllers\Api\EnVivoController::class, 'invitadoPagina'])->name('en-vivo.invitado');
Route::post('/en-vivo/invitado/{codigo}/token', [\App\Http\Controllers\Api\EnVivoController::class, 'invitadoToken'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->name('en-vivo.invitado.token');

/*
|--------------------------------------------------------------------------
| Callbacks de OAuth (públicos: la sesión de editus o la conexión iniciada
| desde la app deciden qué hacer) y conexión de cuentas desde la app del editor
|--------------------------------------------------------------------------
*/
Route::get('/auth/facebook/connect/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.connect.callback');
Route::get('/auth/facebook/link/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.link.callback');
Route::get('/auth/facebook/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.legacy.callback');
Route::get('/auth/youtube/callback', [\App\Http\Controllers\Admin\YoutubeController::class, 'callback'])->name('youtube.callback');

// Enlaces firmados por el backend de esnoticia (u, exp, sig) que la app abre en el navegador
Route::get('/auth/app/facebook', [\App\Http\Controllers\Web\CuentasAppController::class, 'facebook'])->name('app.cuentas.facebook');
Route::get('/auth/app/youtube', [\App\Http\Controllers\Web\CuentasAppController::class, 'youtube'])->name('app.cuentas.youtube');

Route::view('/terms', 'terms')->name('terms');

Route::view('/data-deletion', 'data-deletion')->name('data-deletion');

Route::get('/dashboard', fn() => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Login básico con Facebook (público)
| - Solo public_profile + email (para externos)
|--------------------------------------------------------------------------
*/
Route::get('/auth/facebook/login', [FacebookAuthController::class, 'redirect'])
    ->name('facebook.login');

Route::get('/auth/facebook/login/callback', [FacebookAuthController::class, 'callback'])
    ->name('facebook.login.callback');


/*
|--------------------------------------------------------------------------
| Perfil (requiere sesión)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Zonas por rol (ejemplos)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', fn() => 'solo admins');
});

Route::middleware(['auth', 'role:user'])->group(function () {
    Route::get('/panel', fn() => 'usuarios normales');
});


/*
|--------------------------------------------------------------------------
| Conectar y gestionar páginas (requiere sesión)
| - Aquí se piden los permisos avanzados pages_* (cuando toque)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::get('/meta/pages', [FacebookPageController::class, 'index'])->name('meta.pages.index');
    Route::resource('facebook-pages', FacebookPageController::class)
        ->only(['index', 'show']);
    Route::post('/meta/pages/sync', [FacebookPageController::class, 'sync'])->name('meta.pages.sync');
    Route::post('/meta/pages/publish', [FacebookPageController::class, 'publish'])
        ->middleware('role:admin')->name('meta.pages.publish');

    // reparar tokens caducados o inválidos
    Route::post('/meta/pages/repair-tokens/start', [FacebookPageController::class, 'startRepairTokens'])
        ->name('meta.pages.repairTokens.start');
    Route::post('/meta/pages/repair-tokens/step', [FacebookPageController::class, 'repairTokensStep'])
        ->name('meta.pages.repairTokens.step');

    Route::get('/auth/facebook/connect', [FacebookPageController::class, 'linkRedirect'])->name('facebook.connect');

    // Aliases de compatibilidad
    Route::get('/auth/facebook/link', [FacebookPageController::class, 'linkRedirect'])->name('facebook.link.redirect');

    // Desvincular cuenta/página
    Route::delete('/auth/facebook/unlink', [FacebookPageController::class, 'unlinkAccount'])->name('facebook.unlink');
    Route::delete('/meta/pages/{metaPage}/unlink', [FacebookPageController::class, 'unlinkPage'])->name('meta.pages.unlink');
    Route::post('/meta/pages/{metaPage}/link', [FacebookPageController::class, 'linkSinglePage'])->name('meta.pages.link');

    // Posts de Meta (vista general)
    Route::get('/meta/posts', [MetaPostController::class, 'index'])->name('meta.posts.index');
    Route::get('/meta/posts/{batch}', [MetaPostController::class, 'show'])->name('meta.posts.show');


    Route::post('/meta-posts/{batch}/metrics/start-sync', [MetaPostController::class, 'startMetricsSync'])
        ->name('meta.posts.metrics.startSync');

    Route::post('/meta-posts/{batch}/metrics/step', [MetaPostController::class, 'metricsStep'])
        ->name('meta.posts.metrics.step');

    // Módulo: Mis publicaciones (user)
    Route::get('/mis-publicaciones', [MyPostsController::class, 'index'])->name('mis-posts.index');
    Route::get('/mis-publicaciones/{post}', [MyPostsController::class, 'show'])->name('mis-posts.show');
    Route::put('/mis-publicaciones/{post}', [MyPostsController::class, 'update'])->name('mis-posts.update');

    // Módulo: Informe (admin)
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/informe', [InformeController::class, 'index'])->name('informe.index');
        Route::get('/admin/informe/{key}', [InformeController::class, 'show'])->name('informe.show');
        Route::get('/admin/informe/{key}/pdf', [InformeController::class, 'pdf'])->name('informe.pdf'); // << NUEVO

        // Módulo: App del editor (páginas visibles en la app móvil + plantillas de imagen)
        Route::get('/admin/app-editor', [EditorAppController::class, 'index'])->name('editor-app.index');
        Route::post('/admin/app-editor/paginas', [EditorAppController::class, 'paginas'])->name('editor-app.paginas');
        Route::post('/admin/app-editor/plantillas', [EditorAppController::class, 'plantillaStore'])->name('editor-app.plantillas.store');
        Route::put('/admin/app-editor/plantillas/{plantilla}', [EditorAppController::class, 'plantillaUpdate'])->name('editor-app.plantillas.update');
        Route::delete('/admin/app-editor/plantillas/{plantilla}', [EditorAppController::class, 'plantillaDestroy'])->name('editor-app.plantillas.destroy');
        // YouTube Live: conectar canales (OAuth de Google), visibilidad en la app y desconexión
        Route::get('/auth/youtube/connect', [\App\Http\Controllers\Admin\YoutubeController::class, 'conectar'])->name('youtube.connect');
        Route::post('/admin/youtube/{canal}/visible', [\App\Http\Controllers\Admin\YoutubeController::class, 'visible'])->name('youtube.visible');
        Route::post('/admin/youtube/{canal}/usuarios', [\App\Http\Controllers\Admin\YoutubeController::class, 'usuarios'])->name('youtube.usuarios');
        Route::delete('/admin/youtube/{canal}', [\App\Http\Controllers\Admin\YoutubeController::class, 'desconectar'])->name('youtube.desconectar');
        Route::post('/admin/app-editor/recursos', [EditorAppController::class, 'recursoStore'])->name('editor-app.recursos.store');
        Route::delete('/admin/app-editor/recursos/{recurso}', [EditorAppController::class, 'recursoDestroy'])->name('editor-app.recursos.destroy');

        // Inteligencia de audiencia (campañas, temas, tablero, informes)
        Route::get('/admin/inteligencia', [\App\Http\Controllers\Admin\InteligenciaController::class, 'index'])->name('inteligencia.index');
        Route::post('/admin/inteligencia', [\App\Http\Controllers\Admin\InteligenciaController::class, 'store'])->name('inteligencia.store');
        Route::get('/admin/inteligencia/{campana}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'show'])->name('inteligencia.show');
        Route::put('/admin/inteligencia/{campana}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'update'])->name('inteligencia.update');
        Route::delete('/admin/inteligencia/{campana}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'destroy'])->name('inteligencia.destroy');
        Route::post('/admin/inteligencia/{campana}/temas', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaStore'])->name('inteligencia.temas.store');
        Route::put('/admin/inteligencia/{campana}/temas/{tema}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaUpdate'])->name('inteligencia.temas.update');
        Route::delete('/admin/inteligencia/{campana}/temas/{tema}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaDestroy'])->name('inteligencia.temas.destroy');
        Route::post('/admin/inteligencia/{campana}/publicaciones/{publicacion}/tema', [\App\Http\Controllers\Admin\InteligenciaController::class, 'publicacionTema'])->name('inteligencia.publicacion.tema');
        Route::post('/admin/inteligencia/{campana}/recolectar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'recolectar'])->name('inteligencia.recolectar');
        Route::post('/admin/inteligencia/{campana}/analizar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'analizar'])->name('inteligencia.analizar');
        Route::post('/admin/inteligencia/{campana}/informes', [\App\Http\Controllers\Admin\InteligenciaController::class, 'informeGenerar'])->name('inteligencia.informes.generar');
        Route::get('/admin/inteligencia/{campana}/informes/{informe}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'informe'])->name('inteligencia.informe');
        Route::get('/admin/inteligencia/{campana}/proyeccion', [\App\Http\Controllers\Admin\InteligenciaController::class, 'proyeccion'])->name('inteligencia.proyeccion');
    });
});
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::post('/meta/posts/{post}/retry', [MetaPostController::class, 'retry'])
        ->name('meta.posts.retry');

    Route::post('/meta/posts/batch/{batch}/retry-fails', [MetaPostController::class, 'retryFails'])
        ->name('meta.posts.retryFails');
});

// routes/web.php
Route::post('/meta/pages/favorites/save', [FacebookPageController::class, 'saveFavorites'])
    ->name('meta.pages.favorites.save')
    ->middleware('auth');



/*
|--------------------------------------------------------------------------
| Auth scaffolding
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';