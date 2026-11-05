<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Medio de comunicación del listado de una campaña, con su tipo de contrato y sus canales.
 * Un medio tiene varios canales a la vez (página web, Facebook, Instagram, TikTok…) y en cada red social
 * publica con o sin pauta. Los canales salen en el contrato y deciden qué reporta el medio en su portal.
 */
class MedioCampana extends Model
{
    protected $table = 'campaign_medios';

    /**
     * Canales que puede tener un medio.
     *  nombre: cómo se muestra · texto: cómo va dentro de una frase del contrato · pide: qué dato llena el medio al firmar
     *  pauta: si en ese canal se puede publicar con pauta paga
     */
    public const CANALES = [
        'web' => ['nombre' => 'Página web', 'texto' => 'página web', 'pide' => 'Dirección de la página web', 'ejemplo' => 'www.mimedio.com', 'pauta' => false],
        'facebook' => ['nombre' => 'Facebook', 'texto' => 'Facebook', 'pide' => 'Página de Facebook', 'ejemplo' => 'facebook.com/mimedio', 'pauta' => true],
        'instagram' => ['nombre' => 'Instagram', 'texto' => 'Instagram', 'pide' => 'Usuario de Instagram', 'ejemplo' => '@mimedio', 'pauta' => true],
        'tiktok' => ['nombre' => 'TikTok', 'texto' => 'TikTok', 'pide' => 'Usuario de TikTok', 'ejemplo' => '@mimedio', 'pauta' => true],
        'youtube' => ['nombre' => 'YouTube', 'texto' => 'YouTube', 'pide' => 'Canal de YouTube', 'ejemplo' => 'youtube.com/@mimedio', 'pauta' => true],
        'radio' => ['nombre' => 'Emisora', 'texto' => 'emisora', 'pide' => 'Nombre y frecuencia de la emisora', 'ejemplo' => 'Mi Emisora 96.3 FM', 'pauta' => false],
        'tv' => ['nombre' => 'Televisión', 'texto' => 'televisión', 'pide' => 'Canal de televisión', 'ejemplo' => 'Canal 6', 'pauta' => false],
        'impreso' => ['nombre' => 'Impreso', 'texto' => 'medio impreso', 'pide' => 'Nombre del periódico o revista', 'ejemplo' => 'Diario del Huila', 'pauta' => false],
    ];

    protected $fillable = ['campaign_id', 'nombre', 'clase', 'canales', 'tipo_id', 'correo', 'notas', 'participante_id', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean', 'orden' => 'integer', 'canales' => 'array'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function tipo()
    {
        return $this->belongsTo(ContratoTipo::class, 'tipo_id');
    }

    public function participante()
    {
        return $this->belongsTo(CampaignParticipante::class, 'participante_id');
    }

    public function contratos()
    {
        return $this->hasMany(Contrato::class, 'medio_id');
    }

    /** Canales del medio en el orden de CANALES: ['web' => ['pauta' => false], 'facebook' => ['pauta' => true]] */
    public function canales(): array
    {
        return self::ordenar((array) ($this->canales ?? []));
    }

    public function tieneCanal(string $canal): bool
    {
        return isset($this->canales()[$canal]);
    }

    /** «página web, Facebook con pauta y TikTok» */
    public function canalesTexto(): string
    {
        return self::textoDe($this->canales());
    }

    /** Para listas cortas: «Página web, Facebook, TikTok» */
    public function canalesNombres(): string
    {
        return implode(', ', array_map(fn($k) => self::CANALES[$k]['nombre'], array_keys($this->canales())));
    }

    /** Arma los canales a partir del formulario: los marcados y, de esos, los que llevan pauta. */
    public static function desdeFormulario(array $marcados, array $conPauta = []): array
    {
        $canales = [];
        foreach ($marcados as $k) {
            if (!is_string($k) || !isset(self::CANALES[$k])) continue;
            $canales[$k] = ['pauta' => self::CANALES[$k]['pauta'] && in_array($k, $conPauta, true)];
        }
        return self::ordenar($canales);
    }

    /** Deja solo canales conocidos, en orden, con la pauta como booleano (y el dato del medio, si lo trae). */
    public static function ordenar(array $canales): array
    {
        $limpios = [];
        foreach (self::CANALES as $k => $info) {
            if (!array_key_exists($k, $canales)) continue;
            $c = (array) $canales[$k];
            $limpios[$k] = ['pauta' => $info['pauta'] && !empty($c['pauta'])] + (isset($c['dato']) ? ['dato' => (string) $c['dato']] : []);
        }
        return $limpios;
    }

    /** Nombre de un canal con su pauta: «Facebook (con pauta)». */
    public static function nombreDe(string $canal, bool $pauta = false): string
    {
        return (self::CANALES[$canal]['nombre'] ?? $canal) . ($pauta ? ' (' . __('con pauta') . ')' : '');
    }

    /** Lista en una frase: «página web, Facebook con pauta y TikTok». */
    public static function textoDe(array $canales): string
    {
        $partes = [];
        foreach (self::ordenar($canales) as $k => $c) $partes[] = self::CANALES[$k]['texto'] . ($c['pauta'] ? ' ' . __('con pauta') : '');
        if (count($partes) < 2) return $partes[0] ?? '';
        $ultimo = array_pop($partes);
        return implode(', ', $partes) . ' ' . __('y') . ' ' . $ultimo;
    }
}
