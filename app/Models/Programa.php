<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Programa del contrato de un plan de medios (p. ej. «2-1 Salud infantil y adolescencia») con sus subprogramas. */
class Programa extends Model
{
    protected $table = 'campaign_programas';

    protected $fillable = ['campaign_id', 'codigo', 'nombre', 'subprogramas', 'orden'];

    protected $casts = ['subprogramas' => 'array', 'orden' => 'integer'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function productos()
    {
        return $this->hasMany(Producto::class, 'programa_id');
    }

    /** «2-1. Salud infantil y adolescencia» */
    public function etiqueta(): string
    {
        return trim($this->codigo) !== '' ? trim($this->codigo) . '. ' . $this->nombre : (string) $this->nombre;
    }

    /** @return string[] */
    public function listaSubprogramas(): array
    {
        return array_values(array_filter(array_map(fn($s) => trim((string) $s), (array) $this->subprogramas)));
    }
}
