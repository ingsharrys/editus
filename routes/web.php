<?php

use App\Http\Controllers\Auth\FacebookAuthController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\Meta\FacebookPageController;
use App\Http\Controllers\MetaPostController;
use App\Http\Controllers\MyPostsController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/admin', fn() => 'solo admins');
});

Route::middleware(['auth', 'role:user'])->group(function () {
    Route::get('/panel', fn() => 'usuarios normales');
});



// ===== Conectar y gestionar páginas (requiere sesión en tu app) =====
Route::middleware(['auth'])->group(function () {
    Route::get('/meta/pages', [FacebookPageController::class, 'index'])
        ->name('meta.pages.index');

    Route::post('/meta/pages/sync', [FacebookPageController::class, 'sync'])
        ->name('meta.pages.sync');

    Route::post('/meta/pages/publish', [FacebookPageController::class, 'publish'])
        ->middleware('role:admin')
        ->name('meta.pages.publish');

    Route::get('/auth/facebook/connect', [FacebookPageController::class, 'linkRedirect'])
        ->name('facebook.redirect');

    Route::get('/auth/facebook/connect/callback', [FacebookPageController::class, 'linkCallback'])
        ->name('facebook.callback');

    Route::get('/auth/facebook/link', [FacebookPageController::class, 'linkRedirect'])
        ->name('facebook.link.redirect');

    Route::get('/auth/facebook/link/callback', [FacebookPageController::class, 'linkCallback'])
        ->name('facebook.link.callback');


    // Compatibilidad si Facebook vuelve a este path
    Route::get('/auth/facebook/callback', [FacebookPageController::class, 'linkCallback'])
        ->name('facebook.legacy.callback');

    Route::delete('/auth/facebook/unlink', [FacebookPageController::class, 'unlinkAccount'])
        ->name('facebook.unlink');

    // Desvincular una página específica (pivot del usuario actual)
    Route::delete('/meta/pages/{metaPage}/unlink', [FacebookPageController::class, 'unlinkPage'])
        ->name('meta.pages.unlink');

    Route::post('/meta/pages/{metaPage}/link', [FacebookPageController::class, 'linkSinglePage'])
        ->name('meta.pages.link');

    Route::get('/meta/posts', [MetaPostController::class, 'index'])->name('meta.posts.index');
    Route::get('/meta/posts/{batch}', [MetaPostController::class, 'show'])->name('meta.posts.show');
});
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::get('/mis-publicaciones', [MyPostsController::class, 'index'])->name('mis-posts.index');
    Route::get('/mis-publicaciones/{post}', [MyPostsController::class, 'show'])->name('mis-posts.show');
    Route::put('/mis-publicaciones/{post}', [MyPostsController::class, 'update'])->name('mis-posts.update'); // ← guardar métricas
});

Route::middleware(['auth'])->group(function () {
    Route::get('/admin/informe', [InformeController::class, 'index'])->name('informe.index');
    Route::get('/admin/informe/{key}', [InformeController::class, 'show'])->name('informe.show');
});

require __DIR__ . '/auth.php';
