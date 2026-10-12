<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\YoutubeCanal;
use App\Services\YouTubeLiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Canales de YouTube para transmitir en vivo desde la app: conexión por OAuth de Google
 * (con refresh token), visibilidad en la app y desconexión.
 */
class YoutubeController extends Controller
{
    private const SCOPES = ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'];

    public function conectar(): RedirectResponse
    {
        if ((string) config('services.google.client_id') === '') {
            return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('error', __('Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env de editus.'));
        }
        return Socialite::driver('google')
            ->scopes(self::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true'])
            ->redirectUrl((string) config('services.google.redirect'))
            ->redirect();
    }

    public function callback(Request $request, YouTubeLiveService $youtube): RedirectResponse|\Illuminate\Contracts\View\View
    {
        // Conexión iniciada desde la app del editor (sin sesión de editus)
        if ($vinculo = $request->session()->get(\App\Http\Controllers\Web\CuentasAppController::SESION)) {
            return app(\App\Http\Controllers\Web\CuentasAppController::class)->callbackYoutube($request, (array) $vinculo, $youtube);
        }
        if (!auth()->check() || !auth()->user()->tienePermiso('app_editor')) {
            return redirect()->route('login')->with('error', __('Inicia sesión como administrador para conectar canales.'));
        }
        if ($request->has('error')) {
            return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('error', __('Google devolvió un error: :error', ['error' => $request->get('error_description', $request->get('error'))]));
        }
        try {
            $g = Socialite::driver('google')->redirectUrl((string) config('services.google.redirect'))->stateless()->user();
            $canal = $youtube->registrarCanal((string) $g->token, $g->refreshToken ? (string) $g->refreshToken : null, $g->expiresIn ? (int) $g->expiresIn : null, auth()->id());
        } catch (\Throwable $e) {
            Log::warning('[YouTube] conexión falló', ['err' => $e->getMessage()]);
            return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('error', __('No se pudo conectar el canal: :error', ['error' => $e->getMessage()]));
        }
        $mensaje = $canal->refresh_token
            ? __('Canal «:canal» conectado.', ['canal' => $canal->titulo])
            : __('Canal «:canal» conectado. Google no entregó permiso permanente: si deja de funcionar, desconéctalo y vuelve a conectarlo.', ['canal' => $canal->titulo]);
        return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('success', $mensaje);
    }

    public function visible(Request $request, YoutubeCanal $canal): RedirectResponse
    {
        $canal->visible_en_editor = $request->boolean('visible');
        $canal->save();
        return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('success', $canal->visible_en_editor
            ? __('Canal «:canal» visible en la app.', ['canal' => $canal->titulo])
            : __('Canal «:canal» oculto en la app.', ['canal' => $canal->titulo]));
    }

    /** Qué usuarios de la app ven este canal de la organización (vacío = todos). */
    public function usuarios(Request $request, YoutubeCanal $canal): RedirectResponse
    {
        $canal->app_usuarios = \App\Models\MetaPage::usuariosApp($request->input('usuarios', []));
        $canal->save();
        $mensaje = in_array('*', $canal->app_usuarios, true)
            ? __('Canal «:canal»: lo ven todos los periodistas.', ['canal' => $canal->titulo])
            : ($canal->app_usuarios
                ? __('Canal «:canal»: solo lo ven :periodistas.', ['canal' => $canal->titulo, 'periodistas' => implode(', ', $canal->app_usuarios)])
                : __('Canal «:canal»: no lo ve ningún periodista.', ['canal' => $canal->titulo]));
        return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('success', $mensaje);
    }

    public function desconectar(YoutubeCanal $canal): RedirectResponse
    {
        $nombre = $canal->titulo;
        $canal->delete();
        return redirect()->route('editor-app.index', ['tab' => 'youtube'])->with('success', __('Canal «:canal» desconectado.', ['canal' => $nombre]));
    }
}
