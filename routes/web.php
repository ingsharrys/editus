<?php

use App\Http\Controllers\Auth\FacebookAuthController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\Meta\FacebookPageController;
use App\Http\Controllers\MetaPostController;
use App\Http\Controllers\MyPostsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => view('welcome'));

Route::get('/dashboard', fn() => view('dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Login básico con Facebook (público)
| - Solo public_profile + email (para externos)
|--------------------------------------------------------------------------
*/
Route::get('/auth/facebook/login', [FacebookAuthController::class, 'redirectBasic'])
    ->name('facebook.login');

Route::get('/auth/facebook/login/callback', [FacebookAuthController::class, 'callbackBasic'])
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
    Route::post('/meta/pages/sync', [FacebookPageController::class, 'sync'])->name('meta.pages.sync');
    Route::post('/meta/pages/publish', [FacebookPageController::class, 'publish'])
        ->middleware('role:admin')->name('meta.pages.publish');

    // Iniciar flujo para pedir pages_* (conectar páginas)
    Route::get('/auth/facebook/connect', [FacebookPageController::class, 'linkRedirect'])->name('facebook.redirect');
    // Callback del flujo pages_*
    Route::get('/auth/facebook/connect/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.callback');

    // Aliases de compatibilidad
    Route::get('/auth/facebook/link', [FacebookPageController::class, 'linkRedirect'])->name('facebook.link.redirect');
    Route::get('/auth/facebook/link/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.link.callback');
    Route::get('/auth/facebook/callback', [FacebookPageController::class, 'linkCallback'])->name('facebook.legacy.callback');

    // Desvincular cuenta/página
    Route::delete('/auth/facebook/unlink', [FacebookPageController::class, 'unlinkAccount'])->name('facebook.unlink');
    Route::delete('/meta/pages/{metaPage}/unlink', [FacebookPageController::class, 'unlinkPage'])->name('meta.pages.unlink');
    Route::post('/meta/pages/{metaPage}/link', [FacebookPageController::class, 'linkSinglePage'])->name('meta.pages.link');

    // Posts de Meta (vista general)
    Route::get('/meta/posts', [MetaPostController::class, 'index'])->name('meta.posts.index');
    Route::get('/meta/posts/{batch}', [MetaPostController::class, 'show'])->name('meta.posts.show');

    // Boton obtener métricas
    Route::post('/meta-posts/{batch}/metrics/start', [MetaPostController::class, 'startMetrics'])
        ->name('meta.posts.metrics.start');

    Route::get('/meta-posts/{batch}/metrics/progress', [MetaPostController::class, 'metricsProgress'])
        ->name('meta.posts.metrics.progress');

    // Módulo: Mis publicaciones (user)
    Route::get('/mis-publicaciones', [MyPostsController::class, 'index'])->name('mis-posts.index');
    Route::get('/mis-publicaciones/{post}', [MyPostsController::class, 'show'])->name('mis-posts.show');
    Route::put('/mis-publicaciones/{post}', [MyPostsController::class, 'update'])->name('mis-posts.update');

    // Módulo: Informe (admin)
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/informe', [InformeController::class, 'index'])->name('informe.index');
        Route::get('/admin/informe/{key}', [InformeController::class, 'show'])->name('informe.show');
        Route::get('/admin/informe/{key}/pdf', [InformeController::class, 'pdf'])->name('informe.pdf'); // << NUEVO
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