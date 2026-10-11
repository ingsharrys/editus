<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos de editus a una persona: notificación al celular (app editor) y correo (si hay correo
 * configurado y la persona tiene usuario en la web). Un fallo en un canal no detiene al otro.
 */
class NotificadorService
{
    public function __construct(private PushService $push)
    {
    }

    public static function correoConfigurado(): bool
    {
        return !in_array(config('mail.default'), ['log', 'array', null, ''], true);
    }

    /**
     * @param array $accion ['texto' => 'Abrir el estudio', 'url' => ...] botón del correo
     * @param array $datos  lo que recibe la app al tocar la notificación (pantalla, id)
     */
    public function avisar(?int $userId, ?string $usuarioApp, string $titulo, string $cuerpo, array $accion = [], array $datos = [], array $lineas = []): void
    {
        try {
            $this->push->enviar($userId, $usuarioApp, $titulo, $cuerpo, $datos);
        } catch (\Throwable $e) {
            Log::info('[avisos] push', ['err' => $e->getMessage()]);
        }
        $user = $userId ? User::find($userId) : null;
        if (!$user?->email || !self::correoConfigurado()) return;
        try {
            Mail::send('emails.aviso', ['titulo' => $titulo, 'cuerpo' => $cuerpo, 'accion' => $accion, 'lineas' => $lineas, 'nombre' => $user->name],
                fn($m) => $m->to($user->email, $user->name)->subject($titulo));
        } catch (\Throwable $e) {
            Log::info('[avisos] correo', ['err' => $e->getMessage()]);
        }
    }
}
