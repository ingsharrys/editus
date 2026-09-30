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
    Route::post('/publicaciones/foto', [\App\Http\Controllers\Api\PublicacionesController::class, 'foto'])
        ->name('api.publicaciones.foto');
});
