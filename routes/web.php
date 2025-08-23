<?php

use App\Http\Controllers\Auth\FacebookAuthController;
use App\Http\Controllers\Meta\FacebookPageController;
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

    // Mantén estos nombres porque tu sync() los usa cuando no hay SocialAccount
    Route::get('/auth/facebook/connect', [FacebookPageController::class, 'linkRedirect'])
        ->name('facebook.redirect'); // <-- este nombre es el que espera sync()

    Route::get('/auth/facebook/connect/callback', [FacebookPageController::class, 'linkCallback'])
        ->name('facebook.callback');

    // Compatibilidad si Facebook vuelve a este path
    Route::get('/auth/facebook/callback', [FacebookPageController::class, 'linkCallback'])
        ->name('facebook.legacy.callback');
});

// Route::get('/auth/facebook/redirect', [FacebookAuthController::class, 'redirect'])
//     ->name('facebook.login.redirect');

// Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'callback'])
//     ->name('facebook.login.callback');

require __DIR__ . '/auth.php';
