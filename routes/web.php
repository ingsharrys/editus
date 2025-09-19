<?php

use App\Http\Controllers\Auth\FacebookAuthController;
use App\Http\Controllers\InformeController;
use App\Http\Controllers\Meta\FacebookPageController;
use App\Http\Controllers\MetaPostController;
use App\Http\Controllers\MyPostsController;
use App\Http\Controllers\ProfileController;
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
// 4.1) ¿Puedo escribir en storage y en public/uploads/tmp?
Route::get('/diag/fs', function () {
    $out = [];

    // storage/logs
    try {
        $p = storage_path('logs/diag.txt');
        file_put_contents($p, "ok ".date('c')."\n", FILE_APPEND);
        $out['storage_logs_write'] = file_exists($p) ? 'OK' : 'FAIL';
    } catch (\Throwable $e) { $out['storage_logs_write'] = 'FAIL: '.$e->getMessage(); }

    // storage/app/public/videos/tmp (si existe el symlink)
    try {
        $dir = storage_path('app/public/videos/tmp');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $p = $dir.'/probe.txt';
        file_put_contents($p, "ok ".date('c'));
        $out['storage_public_write'] = file_exists($p) ? 'OK' : 'FAIL';
    } catch (\Throwable $e) { $out['storage_public_write'] = 'FAIL: '.$e->getMessage(); }

    // public/uploads/tmp
    try {
        $dir = public_path('uploads/tmp');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $p = $dir.'/probe.txt';
        file_put_contents($p, "ok ".date('c'));
        $out['public_uploads_write'] = file_exists($p) ? 'OK' : 'FAIL';
    } catch (\Throwable $e) { $out['public_uploads_write'] = 'FAIL: '.$e->getMessage(); }

    return response()->json($out);
});

// 4.2) (opcional) phpinfo para confirmar ini efectivos (BORRAR luego)
Route::get('/diag/phpinfo', function () {
    phpinfo();
});
// GET: formulario simple
Route::get('/diag/upload-form', function () {
    return <<<HTML
<!DOCTYPE html><html><body>
<form method="POST" action="/diag/upload-test" enctype="multipart/form-data">
  <input type="hidden" name="_token" value="".csrf_token()."">
  <input type="file" name="video">
  <button type="submit">Subir</button>
</form>
</body></html>
HTML;
});

// POST: guarda como hace storeVideoPublicTmp()
Route::post('/diag/upload-test', function (\Illuminate\Http\Request $req) {
    if (!$req->hasFile('video')) return ['ok'=>false,'why'=>'no-file'];
    $f = $req->file('video');
    $info = [
        'isValid' => $f->isValid(),
        'error'   => $f->getError(),
        'name'    => $f->getClientOriginalName(),
        'size'    => $f->getSize(),
        'mime'    => $f->getMimeType(),
    ];

    if (!$f->isValid()) return ['ok'=>false,'why'=>'upload-error','info'=>$info];

    // intenta a public/storage
    try {
        \Illuminate\Support\Facades\Storage::disk('public')->exists('.');
        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists('videos/tmp')) {
            \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('videos/tmp');
        }
        $name = (string) \Illuminate\Support\Str::uuid().'.mp4';
        $stream = fopen($f->getRealPath(),'r');
        $saved = \Illuminate\Support\Facades\Storage::disk('public')->put('videos/tmp/'.$name, $stream);
        if (is_resource($stream)) fclose($stream);

        if ($saved) {
            return [
                'ok'=>true,
                'disk'=>'public',
                'url'=>asset('storage/videos/tmp/'.$name),
                'info'=>$info
            ];
        }
    } catch (\Throwable $e) {
        // fallback
    }

    // fallback a public/uploads/tmp
    $dir = public_path('uploads/tmp');
    if (!is_dir($dir)) @mkdir($dir,0755,true);
    $name = (string) \Illuminate\Support\Str::uuid().'.mp4';
    $f->move($dir, $name);
    return [
        'ok'=>true,
        'disk'=>'public/uploads/tmp',
        'url'=>url('uploads/tmp/'.$name),
        'info'=>$info
    ];
});

/*
|--------------------------------------------------------------------------
| Auth scaffolding
|--------------------------------------------------------------------------
*/
require __DIR__ . '/auth.php';