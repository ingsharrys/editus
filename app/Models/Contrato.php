<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Contrato firmado por un medio: número consecutivo, encabezado, documentos, firma y el texto tal como se firmó. */
class Contrato extends Model
{
    protected $table = 'campaign_contratos';

    protected $fillable = [
        'campaign_id', 'medio_id', 'participante_id', 'user_id', 'tipo_id', 'numero', 'codigo', 'datos',
        'logo_path', 'membrete_path', 'rut_path', 'certificado_bancario_path', 'firma_path', 'firma_hash',
        'cuerpo', 'cuerpo_hash', 'pdf_path', 'firmado_at', 'ip', 'user_agent', 'enviado_at', 'envio_error', 'anulado_at',
    ];

    protected $casts = [
        'datos' => 'array',
        'numero' => 'integer',
        'firmado_at' => 'datetime',
        'enviado_at' => 'datetime',
        'anulado_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function medio()
    {
        return $this->belongsTo(MedioCampana::class, 'medio_id');
    }

    public function participante()
    {
        return $this->belongsTo(CampaignParticipante::class, 'participante_id');
    }

    public function tipo()
    {
        return $this->belongsTo(ContratoTipo::class, 'tipo_id');
    }

    public function vigente(): bool
    {
        return $this->firmado_at !== null && $this->anulado_at === null;
    }

    public function dato(string $k, string $defecto = ''): string
    {
        $v = ($this->datos ?? [])[$k] ?? '';
        return is_scalar($v) ? (trim((string) $v) ?: $defecto) : $defecto;
    }

    /** Canales tal como quedaron al firmar: ['facebook' => ['pauta' => true, 'dato' => 'facebook.com/…'], …] */
    public function canales(): array
    {
        return MedioCampana::ordenar((array) (($this->datos ?? [])['canales'] ?? []));
    }
}
