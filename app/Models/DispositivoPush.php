<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Celular registrado para recibir notificaciones (token de Expo). */
class DispositivoPush extends Model
{
    protected $table = 'dispositivos_push';
    protected $fillable = ['user_id', 'usuario_app', 'token', 'plataforma', 'ultimo_uso'];
    protected $casts = ['ultimo_uso' => 'datetime'];
}
