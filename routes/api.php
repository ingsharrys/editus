<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ArticlePublishController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;

Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])
    ->name('whatsapp.verify');

Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'handle'])
    ->name('whatsapp.handle');

// Publicación automática desde el sistema de noticias (backend.esnoticia.org)
// Protegida con el header X-Editus-Token (EDITUS_INGEST_TOKEN en .env)
Route::post('/articulos/publicar', [ArticlePublishController::class, 'store'])
    ->name('articulos.publicar');
