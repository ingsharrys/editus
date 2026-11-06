<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de contrato de una campaña: el texto con variables que se llenan al firmar, los canales que cubre
 * (al elegirlo para un medio, el medio queda con esos canales) y cómo se llama cada parte en las firmas.
 */
class ContratoTipo extends Model
{
    protected $table = 'campaign_contrato_tipos';

    protected $fillable = ['campaign_id', 'clave', 'nombre', 'clase_medio', 'canales', 'rol_medio', 'rol_contratante', 'cuerpo', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer', 'canales' => 'array'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function medios()
    {
        return $this->hasMany(MedioCampana::class, 'tipo_id');
    }

    /** Canales que cubre este contrato: ['web' => ['pauta' => false], …] */
    public function canalesPorDefecto(): array
    {
        return MedioCampana::ordenar((array) ($this->canales ?? []));
    }

    /** ¿El texto usa el valor del contrato? (entonces cada medio necesita su valor para poder firmar) */
    public function usaValor(): bool
    {
        return (bool) preg_match('/\{\s*valor(_letras|_mitad|_mitad_letras)?\s*\}/i', (string) $this->cuerpo);
    }

    /** ¿El texto divide el valor en dos cuotas iguales? (entonces el valor tiene que ser par) */
    public function usaMitad(): bool
    {
        return (bool) preg_match('/\{\s*valor_mitad(_letras)?\s*\}/i', (string) $this->cuerpo);
    }

    public function rolMedio(): string
    {
        return trim((string) $this->rol_medio) ?: __('EL CONTRATISTA');
    }

    public function rolContratante(): string
    {
        return trim((string) $this->rol_contratante) ?: __('EL CONTRATANTE');
    }
}
