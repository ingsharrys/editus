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

Route::get('/auth/facebook/redirect', [FacebookAuthController::class, 'redirect'])
    ->name('facebook.redirect');

Route::get('/auth/facebook/callback', [FacebookAuthController::class, 'callback'])
    ->name('facebook.callback');


Route::middleware(['auth'])->group(function () {
    Route::get('/meta/pages', [FacebookPageController::class, 'index'])->name('meta.pages.index');
    Route::post('/meta/pages/sync', [FacebookPageController::class, 'sync'])->name('meta.pages.sync');
    Route::post('/meta/pages/publish', [FacebookPageController::class, 'publish'])
        ->middleware('role:admin') 
        ->name('meta.pages.publish');
});
require __DIR__ . '/auth.php';
