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
    Route::get('/campanas', [\App\Http\Controllers\Api\PublicacionesController::class, 'campanas'])
        ->name('api.campanas');
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

    // Transmisiones en vivo (LiveKit propio → Facebook Live)
    Route::post('/en-vivo/iniciar', [\App\Http\Controllers\Api\EnVivoController::class, 'iniciar'])->name('api.envivo.iniciar');
    Route::get('/en-vivo/activas', [\App\Http\Controllers\Api\EnVivoController::class, 'activas'])->name('api.envivo.activas');
    Route::post('/en-vivo/{transmision}/plantilla', [\App\Http\Controllers\Api\EnVivoController::class, 'plantilla'])->name('api.envivo.plantilla');
    Route::post('/en-vivo/{transmision}/terminar', [\App\Http\Controllers\Api\EnVivoController::class, 'terminar'])->name('api.envivo.terminar');
    Route::get('/en-vivo/{transmision}/estado', [\App\Http\Controllers\Api\EnVivoController::class, 'estado'])->name('api.envivo.estado');
    Route::post('/en-vivo/preparar', [\App\Http\Controllers\Api\EnVivoController::class, 'preparar'])->name('api.envivo.preparar');
    // En vivos programados con cámaras, pregrabados y celulares (app del editor)
    Route::post('/en-vivo/programar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'programarEnVivo'])->name('api.envivo.programar');
    Route::get('/en-vivo/programadas', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'enVivoProgramadas'])->name('api.envivo.programadas');
    Route::post('/en-vivo/{transmision}/abrir', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'abrirEnVivo'])->name('api.envivo.abrir');
    Route::post('/en-vivo/{transmision}/cancelar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'cancelarEnVivo'])->name('api.envivo.cancelar');
    Route::get('/pregrabados', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'pregrabados'])->name('api.pregrabados');
    Route::post('/pregrabados', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'crearPregrabado'])->name('api.pregrabados.crear');
    Route::post('/pregrabados/{emision}/reanudar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'reanudarPregrabado'])->name('api.pregrabados.reanudar');
    Route::post('/pregrabados/{emision}/subido', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'pregrabadoSubido'])->name('api.pregrabados.subido');
    Route::post('/pregrabados/{emision}/cancelar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'cancelarPregrabado'])->name('api.pregrabados.cancelar');
    Route::post('/pregrabados/{emision}/ahora', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'pregrabadoAhora'])->name('api.pregrabados.ahora');
    Route::post('/pregrabados/{emision}/quitar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'quitarPregrabado'])->name('api.pregrabados.quitar');
    Route::post('/dispositivos', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'registrarDispositivo'])->name('api.dispositivos');
    Route::post('/dispositivos/quitar', [\App\Http\Controllers\Api\ProgramadosAppController::class, 'quitarDispositivo'])->name('api.dispositivos.quitar');
    Route::post('/en-vivo/{transmision}/iniciar', [\App\Http\Controllers\Api\EnVivoController::class, 'salirAlAire'])->name('api.envivo.aire');
    Route::post('/en-vivo/recursos/{recurso}/borrar', [\App\Http\Controllers\Api\EnVivoController::class, 'recursoBorrar'])->name('api.envivo.recursos.borrar');
    Route::get('/en-vivo/youtube', [\App\Http\Controllers\Api\EnVivoController::class, 'youtubeCanales'])->name('api.envivo.youtube');
    Route::get('/en-vivo/recursos', [\App\Http\Controllers\Api\EnVivoController::class, 'recursos'])->name('api.envivo.recursos');
    // Estudio (al estilo StreamYard) desde la app: marcas, archivos propios, notas y comentarios
    Route::get('/en-vivo/estudio', [\App\Http\Controllers\Api\EstudioAppController::class, 'estudio'])->name('api.envivo.estudio');
    Route::post('/en-vivo/marcas', [\App\Http\Controllers\Api\EstudioAppController::class, 'marcaCrear'])->name('api.envivo.marcas.crear');
    Route::post('/en-vivo/marcas/{marca}', [\App\Http\Controllers\Api\EstudioAppController::class, 'marcaGuardar'])->name('api.envivo.marcas.guardar');
    Route::post('/en-vivo/marcas/{marca}/borrar', [\App\Http\Controllers\Api\EstudioAppController::class, 'marcaBorrar'])->name('api.envivo.marcas.borrar');
    Route::post('/en-vivo/activos/{activo}/borrar', [\App\Http\Controllers\Api\EstudioAppController::class, 'activoBorrar'])->name('api.envivo.activos.borrar');
    Route::post('/en-vivo/{transmision}/marca', [\App\Http\Controllers\Api\EstudioAppController::class, 'marcaAplicar'])->name('api.envivo.marca');
    Route::post('/en-vivo/{transmision}/notas', [\App\Http\Controllers\Api\EstudioAppController::class, 'notas'])->name('api.envivo.notas');
    Route::get('/en-vivo/{transmision}/comentarios', [\App\Http\Controllers\Api\EstudioAppController::class, 'comentarios'])->name('api.envivo.comentarios');
    Route::post('/en-vivo/{transmision}/escena', [\App\Http\Controllers\Api\EnVivoController::class, 'escena'])->name('api.envivo.escena');
    Route::post('/en-vivo/{transmision}/invitacion', [\App\Http\Controllers\Api\EnVivoController::class, 'invitacion'])->name('api.envivo.invitacion');
    Route::get('/en-vivo/{transmision}/participantes', [\App\Http\Controllers\Api\EnVivoController::class, 'participantes'])->name('api.envivo.participantes');
    Route::post('/en-vivo/{transmision}/participantes/{identity}/expulsar', [\App\Http\Controllers\Api\EnVivoController::class, 'expulsar'])->name('api.envivo.expulsar');

    // Cuentas de cada usuario de la app (páginas de Facebook y canales de YouTube propios)
    Route::get('/cuentas', [\App\Http\Controllers\Api\CuentasController::class, 'index'])->name('api.cuentas');
    Route::post('/cuentas/facebook/sincronizar', [\App\Http\Controllers\Api\CuentasController::class, 'sincronizarFacebook'])->name('api.cuentas.facebook.sincronizar');
    Route::post('/cuentas/facebook/desconectar', [\App\Http\Controllers\Api\CuentasController::class, 'desconectarFacebook'])->name('api.cuentas.facebook.desconectar');
    Route::post('/cuentas/youtube/{canal}/desconectar', [\App\Http\Controllers\Api\CuentasController::class, 'desconectarYoutube'])->name('api.cuentas.youtube.desconectar');
});

// Subida temporal de videos desde la app del editor: la firma (HMAC con el
// token de integración) la genera el backend de esnoticia, no va token en la app.
Route::post('/subidas/video', [\App\Http\Controllers\Api\SubidasController::class, 'video'])
    ->name('api.subidas.video');
Route::post('/subidas/recurso', [\App\Http\Controllers\Api\SubidasController::class, 'recurso'])
    ->name('api.subidas.recurso');
Route::post('/subidas/activo', [\App\Http\Controllers\Api\SubidasController::class, 'activo'])
    ->middleware('throttle:60,1')->name('api.subidas.activo');

// Plugin de WordPress SharryStreem (autopost). Peticiones firmadas con la licencia del cliente.
Route::prefix('sharrystreem/v1')->middleware('throttle:60,1')->group(function () {
    Route::post('/activar', [\App\Http\Controllers\Api\SharryStreemController::class, 'activar'])->name('sharrystreem.activar');
    Route::get('/estado', [\App\Http\Controllers\Api\SharryStreemController::class, 'estado'])->name('sharrystreem.estado');
    Route::post('/desactivar', [\App\Http\Controllers\Api\SharryStreemController::class, 'desactivar'])->name('sharrystreem.desactivar');
    Route::post('/publicar', [\App\Http\Controllers\Api\SharryStreemController::class, 'publicar'])->name('sharrystreem.publicar');
});
