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

// Landing comercial en el dominio principal (editus.online y www.editus.online)
foreach (array_unique([config('planes.dominio_landing'), 'www.' . config('planes.dominio_landing')]) as $dominioLanding) {
    Route::domain($dominioLanding)->group(function () {
        Route::get('/', [\App\Http\Controllers\LandingController::class, 'index']);
    });
}
// Idioma del sitio (es / en) y moneda de los precios (COP / USD)
Route::get('/idioma/{idioma}', [\App\Http\Controllers\RegionController::class, 'idioma'])->where('idioma', '[a-z]{2}')->name('idioma');
Route::get('/moneda/{moneda}', [\App\Http\Controllers\RegionController::class, 'moneda'])->where('moneda', '[A-Za-z]{3}')->name('moneda');
Route::get('/planes', [\App\Http\Controllers\LandingController::class, 'index'])->name('landing');
Route::get('/suscribirse', [\App\Http\Controllers\LandingController::class, 'formulario'])->name('landing.suscribirse');
Route::post('/suscribirse', [\App\Http\Controllers\LandingController::class, 'suscribir'])->middleware('throttle:10,1')->name('landing.suscribir');

Route::get('/', fn() => view('welcome'));

Route::view('/privacy', 'privacy')->name('privacy');

// Escena de las transmisiones en vivo (la carga el egress de LiveKit con su propio token)
// Escena que compone el egress (HTML estático en infra/en-vivo/escena; también se puede servir desde el VPS, ver LIVEKIT_ESCENA_URL)
Route::get('/en-vivo/escena', fn() => response(file_get_contents(base_path('infra/en-vivo/escena/index.html')), 200, ['Content-Type' => 'text/html; charset=utf-8']))->name('en-vivo.escena');
// Efectos de sonido y música sintetizados (los usan la escena y el estudio)
Route::get('/en-vivo/sonidos.js', fn() => response(file_get_contents(base_path('infra/en-vivo/escena/sonidos.js')), 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']))->name('en-vivo.sonidos');
// Invitados a una transmisión (cámara remota desde el navegador, con código de invitación)
// Eventos de pagos de Wompi (sin sesión; se valida el checksum con WOMPI_EVENTS_SECRET)
Route::post('/webhooks/emisor', \App\Http\Controllers\EmisorWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->middleware('throttle:240,1')->name('webhooks.emisor');
Route::post('/webhooks/wompi', \App\Http\Controllers\WompiWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->middleware('throttle:120,1')->name('webhooks.wompi');
// Eventos de pagos de PayPal (sin sesión; se verifican con la API de PayPal y PAYPAL_WEBHOOK_ID)
Route::post('/webhooks/paypal', \App\Http\Controllers\PayPalWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->middleware('throttle:120,1')->name('webhooks.paypal');
// Eventos de pagos de Square (sin sesión; se valida la firma con SQUARE_WEBHOOK_SIGNATURE_KEY)
Route::post('/webhooks/square', \App\Http\Controllers\SquareWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->middleware('throttle:120,1')->name('webhooks.square');
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

// Enlace de inscripción de un medio a una campaña (con sesión inscribe; sin sesión pide entrar o registrarse)
Route::get('/inscripcion/{token}', [\App\Http\Controllers\CertificadoController::class, 'inscripcion'])
    ->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:30,1')->name('inscripcion.mostrar');

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
| Administración: usuarios y roles con permisos
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'permiso:usuarios'])->group(function () {
    Route::get('/admin/usuarios', [\App\Http\Controllers\Admin\UsuariosController::class, 'index'])->name('usuarios.index');
    Route::post('/admin/usuarios', [\App\Http\Controllers\Admin\UsuariosController::class, 'store'])->name('usuarios.store');
    Route::put('/admin/usuarios/{usuario}', [\App\Http\Controllers\Admin\UsuariosController::class, 'update'])->name('usuarios.update');
    Route::post('/admin/usuarios/{usuario}/activo', [\App\Http\Controllers\Admin\UsuariosController::class, 'activo'])->name('usuarios.activo');
    Route::delete('/admin/usuarios/{usuario}', [\App\Http\Controllers\Admin\UsuariosController::class, 'destroy'])->name('usuarios.destroy');
});
Route::middleware(['auth', 'permiso:roles'])->group(function () {
    Route::get('/admin/roles', [\App\Http\Controllers\Admin\RolesController::class, 'index'])->name('roles.index');
    Route::post('/admin/roles', [\App\Http\Controllers\Admin\RolesController::class, 'store'])->name('roles.store');
    Route::put('/admin/roles/{rol}', [\App\Http\Controllers\Admin\RolesController::class, 'update'])->name('roles.update');
    Route::delete('/admin/roles/{rol}', [\App\Http\Controllers\Admin\RolesController::class, 'destroy'])->name('roles.destroy');
});



/*
|--------------------------------------------------------------------------
| Conectar y gestionar páginas (requiere sesión)
| - Aquí se piden los permisos avanzados pages_* (cuando toque)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Mi suscripción (planes, pago con Wompi, páginas del plan, licencia SharryStreem)
    Route::get('/suscripcion', [\App\Http\Controllers\SuscripcionController::class, 'index'])->middleware('permiso:suscripcion')->name('suscripcion.index');
    Route::post('/suscripcion/pagar', [\App\Http\Controllers\SuscripcionController::class, 'pagar'])->middleware('permiso:suscripcion')->name('suscripcion.pagar');
    Route::get('/suscripcion/resultado', [\App\Http\Controllers\SuscripcionController::class, 'resultado'])->middleware('permiso:suscripcion')->name('suscripcion.resultado');
    Route::get('/suscripcion/square', [\App\Http\Controllers\SuscripcionController::class, 'resultadoSquare'])->middleware('permiso:suscripcion')->name('suscripcion.square');
    Route::get('/suscripcion/paypal', [\App\Http\Controllers\SuscripcionController::class, 'resultadoPaypal'])->middleware('permiso:suscripcion')->name('suscripcion.paypal');
    Route::get('/suscripcion/paypal/cancelar', [\App\Http\Controllers\SuscripcionController::class, 'cancelarPaypal'])->middleware('permiso:suscripcion')->name('suscripcion.paypal.cancelar');
    Route::post('/suscripcion/paginas', [\App\Http\Controllers\SuscripcionController::class, 'paginas'])->middleware('permiso:suscripcion')->name('suscripcion.paginas');
    Route::post('/suscripcion/licencia', [\App\Http\Controllers\SuscripcionController::class, 'regenerarLicencia'])->middleware('permiso:suscripcion')->name('suscripcion.licencia');
    Route::post('/suscripcion/sitio/liberar', [\App\Http\Controllers\SuscripcionController::class, 'liberarSitio'])->middleware('permiso:suscripcion')->name('suscripcion.sitio.liberar');
    // Estudio de transmisión en vivo en la web (mismo flujo que la app del editor)
    Route::get('/en-vivo', [\App\Http\Controllers\Web\EnVivoWebController::class, 'index'])->middleware('permiso:en_vivo')->name('en-vivo.web');
    // Videos pregrabados que salen en vivo a una hora programada
    Route::get('/en-vivo/pregrabados', [\App\Http\Controllers\Web\PregrabadosController::class, 'index'])->middleware('permiso:en_vivo')->name('pregrabados.index');
    Route::get('/en-vivo/pregrabados/estado', [\App\Http\Controllers\Web\PregrabadosController::class, 'estado'])->middleware('permiso:en_vivo')->name('pregrabados.estado');
    Route::post('/en-vivo/pregrabados', [\App\Http\Controllers\Web\PregrabadosController::class, 'store'])->middleware('throttle:20,1')->middleware('permiso:en_vivo')->name('pregrabados.store');
    Route::post('/en-vivo/pregrabados/{emision}/reanudar', [\App\Http\Controllers\Web\PregrabadosController::class, 'reanudar'])->middleware('permiso:en_vivo')->name('pregrabados.reanudar');
    Route::post('/en-vivo/pregrabados/{emision}/subido', [\App\Http\Controllers\Web\PregrabadosController::class, 'subido'])->middleware('permiso:en_vivo')->name('pregrabados.subido');
    Route::post('/en-vivo/pregrabados/{emision}/cancelar', [\App\Http\Controllers\Web\PregrabadosController::class, 'cancelar'])->middleware('permiso:en_vivo')->name('pregrabados.cancelar');
    Route::post('/en-vivo/pregrabados/{emision}/ahora', [\App\Http\Controllers\Web\PregrabadosController::class, 'ahora'])->middleware('permiso:en_vivo')->name('pregrabados.ahora');
    Route::delete('/en-vivo/pregrabados/{emision}', [\App\Http\Controllers\Web\PregrabadosController::class, 'destroy'])->middleware('permiso:en_vivo')->name('pregrabados.destroy');
    // Panel del estudio: marcas, archivos propios, notas y comentarios en vivo
    Route::post('/en-vivo/marcas', [\App\Http\Controllers\Web\EstudioController::class, 'marcaCrear'])->middleware('permiso:en_vivo')->name('en-vivo.marcas.crear');
    Route::post('/en-vivo/marcas/{marca}', [\App\Http\Controllers\Web\EstudioController::class, 'marcaGuardar'])->middleware('permiso:en_vivo')->name('en-vivo.marcas.guardar');
    Route::delete('/en-vivo/marcas/{marca}', [\App\Http\Controllers\Web\EstudioController::class, 'marcaBorrar'])->middleware('permiso:en_vivo')->name('en-vivo.marcas.borrar');
    Route::post('/en-vivo/activos', [\App\Http\Controllers\Web\EstudioController::class, 'activoSubir'])->middleware('throttle:60,1')->middleware('permiso:en_vivo')->name('en-vivo.activos.subir');
    Route::delete('/en-vivo/activos/{activo}', [\App\Http\Controllers\Web\EstudioController::class, 'activoBorrar'])->middleware('permiso:en_vivo')->name('en-vivo.activos.borrar');
    Route::post('/en-vivo/{transmision}/marca', [\App\Http\Controllers\Web\EstudioController::class, 'marcaAplicar'])->middleware('permiso:en_vivo')->name('en-vivo.web.marca');
    Route::post('/en-vivo/{transmision}/notas', [\App\Http\Controllers\Web\EstudioController::class, 'notas'])->middleware('permiso:en_vivo')->name('en-vivo.web.notas');
    Route::get('/en-vivo/{transmision}/comentarios', [\App\Http\Controllers\Web\EstudioController::class, 'comentarios'])->middleware('throttle:40,1')->middleware('permiso:en_vivo')->name('en-vivo.web.comentarios');
    Route::post('/en-vivo/preparar', [\App\Http\Controllers\Web\EnVivoWebController::class, 'preparar'])->middleware('permiso:en_vivo')->name('en-vivo.web.preparar');
    Route::post('/en-vivo/programar', [\App\Http\Controllers\Web\EnVivoWebController::class, 'programar'])->middleware('throttle:20,1')->middleware('permiso:en_vivo')->name('en-vivo.web.programar');
    Route::post('/en-vivo/{transmision}/abrir', [\App\Http\Controllers\Web\EnVivoWebController::class, 'abrir'])->middleware('permiso:en_vivo')->name('en-vivo.web.abrir');
    Route::post('/en-vivo/{transmision}/cancelar', [\App\Http\Controllers\Web\EnVivoWebController::class, 'cancelarProgramada'])->middleware('permiso:en_vivo')->name('en-vivo.web.cancelar');
    Route::post('/en-vivo/{transmision}/aire', [\App\Http\Controllers\Web\EnVivoWebController::class, 'aire'])->middleware('permiso:en_vivo')->name('en-vivo.web.aire');
    Route::post('/en-vivo/{transmision}/escena', [\App\Http\Controllers\Web\EnVivoWebController::class, 'escena'])->middleware('permiso:en_vivo')->name('en-vivo.web.escena');
    Route::post('/en-vivo/{transmision}/plantilla', [\App\Http\Controllers\Web\EnVivoWebController::class, 'plantilla'])->middleware('permiso:en_vivo')->name('en-vivo.web.plantilla');
    Route::post('/en-vivo/{transmision}/invitacion', [\App\Http\Controllers\Web\EnVivoWebController::class, 'invitacion'])->middleware('permiso:en_vivo')->name('en-vivo.web.invitacion');
    Route::get('/en-vivo/{transmision}/participantes', [\App\Http\Controllers\Web\EnVivoWebController::class, 'participantes'])->middleware('permiso:en_vivo')->name('en-vivo.web.participantes');
    Route::post('/en-vivo/{transmision}/participantes/{identity}/expulsar', [\App\Http\Controllers\Web\EnVivoWebController::class, 'expulsar'])->middleware('permiso:en_vivo')->name('en-vivo.web.expulsar');
    Route::get('/en-vivo/{transmision}/estado', [\App\Http\Controllers\Web\EnVivoWebController::class, 'estado'])->middleware('permiso:en_vivo')->name('en-vivo.web.estado');
    Route::post('/en-vivo/{transmision}/terminar', [\App\Http\Controllers\Web\EnVivoWebController::class, 'terminar'])->middleware('permiso:en_vivo')->name('en-vivo.web.terminar');
    Route::get('/meta/pages', [FacebookPageController::class, 'index'])->middleware('permiso:paginas')->name('meta.pages.index');
    Route::resource('facebook-pages', FacebookPageController::class)
        ->only(['index', 'show'])->middleware('permiso:paginas');
    Route::post('/meta/pages/sync', [FacebookPageController::class, 'sync'])->middleware('permiso:paginas')->name('meta.pages.sync');
    Route::post('/meta/pages/publish', [FacebookPageController::class, 'publish'])
        ->middleware('permiso:paginas.publicar')->name('meta.pages.publish');

    // Vincular página con un medio de esnoticia (publicación automática)
    Route::post('/meta/pages/{metaPage}/medio', [FacebookPageController::class, 'updateMedio'])
        ->middleware('permiso:paginas.medio')->name('meta.pages.medio');

    // reparar tokens caducados o inválidos
    Route::post('/meta/pages/repair-tokens/start', [FacebookPageController::class, 'startRepairTokens'])
        ->middleware('permiso:paginas')->name('meta.pages.repairTokens.start');
    Route::post('/meta/pages/repair-tokens/step', [FacebookPageController::class, 'repairTokensStep'])
        ->middleware('permiso:paginas')->name('meta.pages.repairTokens.step');

    Route::get('/auth/facebook/connect', [FacebookPageController::class, 'linkRedirect'])->middleware('permiso:paginas')->name('facebook.connect');

    // Aliases de compatibilidad
    Route::get('/auth/facebook/link', [FacebookPageController::class, 'linkRedirect'])->middleware('permiso:paginas')->name('facebook.link.redirect');

    // Desvincular cuenta/página
    Route::delete('/auth/facebook/unlink', [FacebookPageController::class, 'unlinkAccount'])->middleware('permiso:paginas')->name('facebook.unlink');
    Route::delete('/meta/pages/{metaPage}/unlink', [FacebookPageController::class, 'unlinkPage'])->middleware('permiso:paginas')->name('meta.pages.unlink');
    Route::post('/meta/pages/{metaPage}/link', [FacebookPageController::class, 'linkSinglePage'])->middleware('permiso:paginas')->name('meta.pages.link');

    // Estadísticas (dashboard)
    Route::get('/estadisticas', [\App\Http\Controllers\StatsController::class, 'index'])->middleware('permiso:estadisticas')->name('stats.index');
    Route::post('/estadisticas/collect', [\App\Http\Controllers\StatsController::class, 'collect'])->middleware('permiso:estadisticas')->name('stats.collect');

    // Informe de publicaciones (explorador con filtros y exportación)
    Route::get('/informes/publicaciones', [\App\Http\Controllers\ReportsController::class, 'posts'])->middleware('permiso:informes')->name('reports.posts');
    Route::get('/informes/publicaciones/csv', [\App\Http\Controllers\ReportsController::class, 'exportCsv'])->middleware('permiso:informes')->name('reports.posts.csv');
    Route::get('/informes/analisis', [\App\Http\Controllers\ReportsController::class, 'analytics'])->middleware('permiso:informes')->name('reports.analytics');

    // Campañas (gestión, solo admin — validado en el controlador)
    Route::get('/campanas', [\App\Http\Controllers\CampaignController::class, 'index'])->middleware('permiso:campanas')->name('campaigns.index');
        Route::get('/campanas/nueva', [\App\Http\Controllers\CampaignController::class, 'create'])->middleware('permiso:campanas')->name('campaigns.create');
        Route::get('/campanas/{campaign}/editar', [\App\Http\Controllers\CampaignController::class, 'edit'])->middleware('permiso:campanas')->name('campaigns.edit');
    Route::post('/campanas', [\App\Http\Controllers\CampaignController::class, 'store'])->middleware('permiso:campanas')->name('campaigns.store');
    Route::post('/campanas/{campaign}', [\App\Http\Controllers\CampaignController::class, 'update'])->middleware('permiso:campanas')->name('campaigns.update');
    Route::post('/campanas/{campaign}/toggle', [\App\Http\Controllers\CampaignController::class, 'toggle'])->middleware('permiso:campanas')->name('campaigns.toggle');
    // Inscripción de medios y certificados de publicación de la campaña
    Route::post('/campanas/{campaign}/inscripcion/regenerar', [\App\Http\Controllers\CampaignController::class, 'regenerarEnlace'])->middleware('permiso:campanas')->name('campaigns.inscripcion.regenerar');
    Route::delete('/campanas/{campaign}/participantes/{participante}', [\App\Http\Controllers\CampaignController::class, 'quitarParticipante'])->middleware('permiso:campanas')->name('campaigns.participantes.quitar');
    Route::get('/campanas/{campaign}/piezas', [\App\Http\Controllers\CampaignController::class, 'piezas'])->middleware('permiso:campanas')->name('campaigns.piezas');
    Route::post('/campanas/{campaign}/piezas', [\App\Http\Controllers\CampaignController::class, 'guardarPiezas'])->middleware('permiso:campanas')->name('campaigns.piezas.guardar');
    // Certificado del medio inscrito (lo llena y firma el medio; el administrador también lo ve)
    Route::get('/certificado/{campaign}', [\App\Http\Controllers\CertificadoController::class, 'formulario'])->name('certificados.formulario');
    Route::post('/certificado/{campaign}', [\App\Http\Controllers\CertificadoController::class, 'guardar'])->middleware('throttle:20,1')->name('certificados.guardar');
    Route::get('/certificados/{participante}', [\App\Http\Controllers\CertificadoController::class, 'ver'])->name('certificados.ver');
    Route::get('/certificados/{participante}/pdf', [\App\Http\Controllers\CertificadoController::class, 'pdf'])->name('certificados.pdf');

    // Posts de Meta (vista general)
    Route::get('/meta/posts', [MetaPostController::class, 'index'])->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.index');
    Route::get('/meta/posts/{batch}', [MetaPostController::class, 'show'])->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.show');


    Route::post('/meta-posts/{batch}/metrics/start-sync', [MetaPostController::class, 'startMetricsSync'])
        ->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.metrics.startSync');

    Route::post('/meta-posts/{batch}/metrics/step', [MetaPostController::class, 'metricsStep'])
        ->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.metrics.step');

    // Módulo: Mis publicaciones (user)
    Route::get('/mis-publicaciones', [MyPostsController::class, 'index'])->middleware('permiso:mis_publicaciones')->name('mis-posts.index');
    Route::get('/mis-publicaciones/{post}', [MyPostsController::class, 'show'])->middleware('permiso:mis_publicaciones')->name('mis-posts.show');
    Route::put('/mis-publicaciones/{post}', [MyPostsController::class, 'update'])->middleware('permiso:mis_publicaciones')->name('mis-posts.update');

    // Módulo: Informe (admin)
    Route::group([], function () { // cada módulo con su permiso (config/permisos.php)
        Route::get('/admin/informe', [InformeController::class, 'index'])->middleware('permiso:informe_admin')->name('informe.index');
        Route::get('/admin/informe/{key}', [InformeController::class, 'show'])->middleware('permiso:informe_admin')->name('informe.show');
        Route::get('/admin/informe/{key}/pdf', [InformeController::class, 'pdf'])->middleware('permiso:informe_admin')->name('informe.pdf'); // << NUEVO

        // Módulo: App del editor (páginas visibles en la app móvil + plantillas de imagen)
        Route::get('/admin/app-editor', [EditorAppController::class, 'index'])->middleware('permiso:app_editor')->name('editor-app.index');
        Route::post('/admin/app-editor/paginas', [EditorAppController::class, 'paginas'])->middleware('permiso:app_editor')->name('editor-app.paginas');
        Route::get('/admin/app-editor/plantillas', [EditorAppController::class, 'plantillas'])->middleware('permiso:plantillas')->name('editor-app.plantillas.index');
        Route::post('/admin/app-editor/plantillas', [EditorAppController::class, 'plantillaStore'])->middleware('permiso:plantillas')->name('editor-app.plantillas.store');
        Route::put('/admin/app-editor/plantillas/{plantilla}', [EditorAppController::class, 'plantillaUpdate'])->middleware('permiso:plantillas')->name('editor-app.plantillas.update');
        Route::delete('/admin/app-editor/plantillas/{plantilla}', [EditorAppController::class, 'plantillaDestroy'])->middleware('permiso:plantillas')->name('editor-app.plantillas.destroy');
        // YouTube Live: conectar canales (OAuth de Google), visibilidad en la app y desconexión
        Route::get('/auth/youtube/connect', [\App\Http\Controllers\Admin\YoutubeController::class, 'conectar'])->middleware('permiso:app_editor')->name('youtube.connect');
        Route::post('/admin/youtube/{canal}/visible', [\App\Http\Controllers\Admin\YoutubeController::class, 'visible'])->middleware('permiso:app_editor')->name('youtube.visible');
        Route::post('/admin/youtube/{canal}/usuarios', [\App\Http\Controllers\Admin\YoutubeController::class, 'usuarios'])->middleware('permiso:app_editor')->name('youtube.usuarios');
        Route::delete('/admin/youtube/{canal}', [\App\Http\Controllers\Admin\YoutubeController::class, 'desconectar'])->middleware('permiso:app_editor')->name('youtube.desconectar');
        Route::post('/admin/app-editor/recursos', [EditorAppController::class, 'recursoStore'])->middleware('permiso:app_editor')->name('editor-app.recursos.store');
        Route::delete('/admin/app-editor/recursos/{recurso}', [EditorAppController::class, 'recursoDestroy'])->middleware('permiso:app_editor')->name('editor-app.recursos.destroy');

        // Inteligencia de audiencia (campañas, temas, tablero, informes)
        Route::get('/admin/suscripciones', [\App\Http\Controllers\Admin\SuscripcionesController::class, 'index'])->middleware('permiso:suscripciones_admin')->name('admin.suscripciones');
        Route::post('/admin/suscripciones/{user}/activar', [\App\Http\Controllers\Admin\SuscripcionesController::class, 'activar'])->middleware('permiso:suscripciones_admin')->name('admin.suscripciones.activar');
        Route::post('/admin/suscripciones/{user}/cancelar', [\App\Http\Controllers\Admin\SuscripcionesController::class, 'cancelar'])->middleware('permiso:suscripciones_admin')->name('admin.suscripciones.cancelar');
        Route::post('/admin/suscripciones/{user}/exento', [\App\Http\Controllers\Admin\SuscripcionesController::class, 'exento'])->middleware('permiso:suscripciones_admin')->name('admin.suscripciones.exento');
        Route::get('/admin/inteligencia', [\App\Http\Controllers\Admin\InteligenciaController::class, 'index'])->middleware('permiso:inteligencia')->name('inteligencia.index');
        Route::get('/admin/inteligencia/general', [\App\Http\Controllers\Admin\InteligenciaController::class, 'general'])->middleware('permiso:inteligencia')->name('inteligencia.general');
        Route::post('/admin/inteligencia/general/recolectar/iniciar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'recolectarIniciar'])->middleware('permiso:inteligencia')->name('inteligencia.general.recolectar.iniciar');
        Route::post('/admin/inteligencia/general/recolectar/paso', [\App\Http\Controllers\Admin\InteligenciaController::class, 'recolectarPaso'])->middleware('permiso:inteligencia')->name('inteligencia.general.recolectar.paso');
        Route::post('/admin/inteligencia/consultar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'consultar'])->middleware('permiso:inteligencia')->name('inteligencia.consultar');
        Route::post('/admin/inteligencia/diagnostico', [\App\Http\Controllers\Admin\InteligenciaController::class, 'diagnosticar'])->middleware('permiso:inteligencia')->name('inteligencia.diagnostico');
        Route::get('/admin/inteligencia/simular', [\App\Http\Controllers\Admin\InteligenciaController::class, 'simular'])->middleware('permiso:inteligencia')->name('inteligencia.simular');
        Route::get('/admin/inteligencia/{campana}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'show'])->middleware('permiso:inteligencia')->name('inteligencia.show');
        Route::post('/admin/inteligencia/{campana}/temas', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaStore'])->middleware('permiso:inteligencia')->name('inteligencia.temas.store');
        Route::put('/admin/inteligencia/{campana}/temas/{tema}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaUpdate'])->middleware('permiso:inteligencia')->name('inteligencia.temas.update');
        Route::delete('/admin/inteligencia/{campana}/temas/{tema}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'temaDestroy'])->middleware('permiso:inteligencia')->name('inteligencia.temas.destroy');
        Route::post('/admin/inteligencia/{campana}/publicaciones/{publicacion}/tema', [\App\Http\Controllers\Admin\InteligenciaController::class, 'publicacionTema'])->middleware('permiso:inteligencia')->name('inteligencia.publicacion.tema');
        Route::post('/admin/inteligencia/{campana}/recolectar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'recolectar'])->middleware('permiso:inteligencia')->name('inteligencia.recolectar');
        Route::post('/admin/inteligencia/{campana}/analizar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'analizar'])->middleware('permiso:inteligencia')->name('inteligencia.analizar');
        Route::post('/admin/inteligencia/{campana}/analizar/paso', [\App\Http\Controllers\Admin\InteligenciaController::class, 'analizarPaso'])->middleware('permiso:inteligencia')->name('inteligencia.analizar.paso');
        Route::post('/admin/inteligencia/{campana}/radar', [\App\Http\Controllers\Admin\InteligenciaController::class, 'radarIniciar'])->middleware('permiso:inteligencia')->name('inteligencia.radar.iniciar');
        Route::post('/admin/inteligencia/{campana}/radar/{radar}/paso', [\App\Http\Controllers\Admin\InteligenciaController::class, 'radarPaso'])->middleware('permiso:inteligencia')->name('inteligencia.radar.paso');
        Route::post('/admin/inteligencia/{campana}/informes', [\App\Http\Controllers\Admin\InteligenciaController::class, 'informeGenerar'])->middleware('permiso:inteligencia')->name('inteligencia.informes.generar');
        Route::get('/admin/inteligencia/{campana}/informes/{informe}', [\App\Http\Controllers\Admin\InteligenciaController::class, 'informe'])->middleware('permiso:inteligencia')->name('inteligencia.informe');
        Route::get('/admin/inteligencia/{campana}/proyeccion', [\App\Http\Controllers\Admin\InteligenciaController::class, 'proyeccion'])->middleware('permiso:inteligencia')->name('inteligencia.proyeccion');
    });
});
// routes/web.php
Route::middleware(['auth'])->group(function () {
    Route::post('/meta/posts/{post}/retry', [MetaPostController::class, 'retry'])
        ->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.retry');

    Route::post('/meta/posts/batch/{batch}/retry-fails', [MetaPostController::class, 'retryFails'])
        ->middleware('permiso:publicaciones|mis_publicaciones')->name('meta.posts.retryFails');
});

// routes/web.php
Route::post('/meta/pages/favorites/save', [FacebookPageController::class, 'saveFavorites'])
    ->middleware('permiso:paginas')->name('meta.pages.favorites.save')
    ->middleware('auth');



/*
|--------------------------------------------------------------------------
| Auth scaffolding
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';