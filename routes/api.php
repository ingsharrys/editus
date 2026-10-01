<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;

Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])
    ->name('whatsapp.verify');

Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'handle'])
    ->name('whatsapp.handle');

/*
|--------------------------------------------------------------------------
| Integración con el backend de esnoticia (sección "Redes" de la app)
| Protegida con el header X-Editus-Token = EDITUS_INGEST_TOKEN (.env)
|--------------------------------------------------------------------------
*/
Route::middleware('editus.token')->group(function () {
    Route::get('/paginas', [\App\Http\Controllers\Api\PublicacionesController::class, 'paginas'])
        ->name('api.paginas');
    Route::get('/plantillas', [\App\Http\Controllers\Api\PublicacionesController::class, 'plantillas'])
        ->name('api.plantillas');
    Route::get('/videos', [\App\Http\Controllers\Api\PublicacionesController::class, 'videos'])
        ->name('api.videos');
    Route::post('/publicaciones/foto', [\App\Http\Controllers\Api\PublicacionesController::class, 'foto'])
        ->name('api.publicaciones.foto');
    Route::post('/publicaciones/video', [\App\Http\Controllers\Api\PublicacionesController::class, 'video'])
        ->name('api.publicaciones.video');
    Route::post('/publicaciones/video/descripcion', [\App\Http\Controllers\Api\PublicacionesController::class, 'descripcionVideo'])
        ->name('api.publicaciones.video.descripcion');
    Route::post('/publicaciones/metricas', [\App\Http\Controllers\Api\PublicacionesController::class, 'metricas'])
        ->name('api.publicaciones.metricas');
});

// Subida temporal de videos desde la app del editor: la firma (HMAC con el
// token de integración) la genera el backend de esnoticia, no va token en la app.
Route::post('/subidas/video', [\App\Http\Controllers\Api\SubidasController::class, 'video'])
    ->name('api.subidas.video');
