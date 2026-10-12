<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marca del estudio (como las "Marcas" de StreamYard): todo lo que da identidad a una
 * transmisión y se reutiliza de una a otra. Al aplicarla, sus valores pasan a la plantilla
 * de la transmisión (metadata de la sala); los banners guardados quedan solo aquí.
 */
class MarcaEnVivo extends Model
{
    public const DEFECTO = [
        'color_etiqueta' => '#C8102E', 'tema' => 'clasico', 'fuente' => 'inter',
        'logo_url' => '', 'logo_texto' => '', 'logo_pos' => 'izq', 'color_logo' => '#FFFFFF',
        'superposicion' => null, 'desvanecer' => true, 'fondo' => ['tipo' => 'preset', 'valor' => 'noche'],
        'mostrar_nombres' => true, 'en_vivo' => true, 'etiqueta' => 'EN VIVO', 'pie' => '', 'hashtag' => '',
        'marco_url' => '', 'banners' => [],
    ];
    /** Claves de la marca que van a la plantilla (lo demás, como los banners, no viaja a la sala). */
    public const A_PLANTILLA = ['color_etiqueta', 'tema', 'fuente', 'logo_url', 'logo_texto', 'logo_pos', 'color_logo', 'superposicion', 'desvanecer',
        'fondo', 'mostrar_nombres', 'en_vivo', 'etiqueta', 'pie', 'hashtag', 'marco_url'];

    protected $table = 'marcas_en_vivo';
    protected $fillable = ['user_id', 'nombre', 'datos'];
    protected $casts = ['datos' => 'array'];

    public function datosCompletos(): array
    {
        return array_merge(self::DEFECTO, (array) ($this->datos ?? []));
    }

    public function paraApi(): array
    {
        return ['id' => $this->id, 'nombre' => $this->nombre, 'datos' => $this->datosCompletos()];
    }

    public function paraPlantilla(): array
    {
        return array_intersect_key($this->datosCompletos(), array_flip(self::A_PLANTILLA));
    }
}
