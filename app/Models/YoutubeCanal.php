<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YoutubeCanal extends Model
{
    protected $table = 'youtube_canales';
    protected $fillable = ['user_id', 'usuario_app', 'channel_id', 'titulo', 'foto', 'access_token', 'refresh_token', 'expira_en', 'stream_id', 'visible_en_editor', 'app_usuarios'];
    protected $casts = ['expira_en' => 'datetime', 'visible_en_editor' => 'boolean', 'app_usuarios' => 'array'];
    protected $hidden = ['access_token', 'refresh_token'];

    /** ¿Este usuario de la app puede ver el canal de la organización? Lista vacía = todos. */
    public function visibleParaUsuarioApp(?string $nombre): bool
    {
        $lista = $this->app_usuarios;
        if ($lista === null) return true;                  // nunca configurada: todos (compatibilidad)
        if (!is_array($lista) || !$lista) return false;    // lista vacía: ningún periodista
        if (in_array('*', $lista, true)) return true;      // todos los periodistas
        return $nombre !== null && in_array(strtolower(trim($nombre)), $lista, true);
    }

    public function paraApi(?string $usuarioApp = null): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'nombre' => $this->titulo,
            'foto' => $this->foto,
            'url' => 'https://www.youtube.com/channel/' . $this->channel_id,
            // Conectado por este usuario desde la app (true) o por la organización desde la web de editus (false)
            'propio' => $usuarioApp !== null && (string) $this->usuario_app === $usuarioApp,
        ];
    }
}
