<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YoutubeCanal extends Model
{
    protected $table = 'youtube_canales';
    protected $fillable = ['user_id', 'channel_id', 'titulo', 'foto', 'access_token', 'refresh_token', 'expira_en', 'stream_id', 'visible_en_editor'];
    protected $casts = ['expira_en' => 'datetime', 'visible_en_editor' => 'boolean'];
    protected $hidden = ['access_token', 'refresh_token'];

    public function paraApi(): array
    {
        return ['id' => $this->id, 'channel_id' => $this->channel_id, 'nombre' => $this->titulo, 'foto' => $this->foto, 'url' => 'https://www.youtube.com/channel/' . $this->channel_id];
    }
}
