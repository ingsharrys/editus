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
            return redirect()->route('editor-app.index')->with('error', 'Faltan GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el .env de editus.')->withFragment('youtube');
        }
        return Socialite::driver('google')
            ->scopes(self::SCOPES)
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'true'])
            ->redirectUrl((string) config('services.google.redirect'))
            ->redirect();
    }

    public function callback(Request $request, YouTubeLiveService $youtube): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('editor-app.index')->with('error', 'Google devolvió un error: ' . $request->get('error_description', $request->get('error')))->withFragment('youtube');
        }
        try {
            $g = Socialite::driver('google')->redirectUrl((string) config('services.google.redirect'))->stateless()->user();
            $canal = $youtube->registrarCanal((string) $g->token, $g->refreshToken ? (string) $g->refreshToken : null, $g->expiresIn ? (int) $g->expiresIn : null, auth()->id());
        } catch (\Throwable $e) {
            Log::warning('[YouTube] conexión falló', ['err' => $e->getMessage()]);
            return redirect()->route('editor-app.index')->with('error', 'No se pudo conectar el canal: ' . $e->getMessage())->withFragment('youtube');
        }
        $aviso = $canal->refresh_token ? '' : ' Google no entregó permiso permanente: si deja de funcionar, desconéctalo y vuelve a conectarlo.';
        return redirect()->route('editor-app.index')->with('success', "Canal «{$canal->titulo}» conectado.{$aviso}")->withFragment('youtube');
    }

    public function visible(Request $request, YoutubeCanal $canal): RedirectResponse
    {
        $canal->visible_en_editor = $request->boolean('visible');
        $canal->save();
        return redirect()->route('editor-app.index')->with('success', "Canal «{$canal->titulo}» " . ($canal->visible_en_editor ? 'visible' : 'oculto') . ' en la app.')->withFragment('youtube');
    }

    public function desconectar(YoutubeCanal $canal): RedirectResponse
    {
        $nombre = $canal->titulo;
        $canal->delete();
        return redirect()->route('editor-app.index')->with('success', "Canal «{$nombre}» desconectado.")->withFragment('youtube');
    }
}
