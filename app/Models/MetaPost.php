<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class MetaPost extends Model
{
     /** Ventanas de disponibilidad de métricas */
    public const METRICS_OPEN_HOURS  = 36; // abre R1 a las 36h
    public const METRICS_REOPEN_DAYS = 30; // abre R2 a los 30 días

    protected $fillable = [
        'batch_uuid',
        'meta_page_id',
        'user_id',
        'type',
        'message',
        'link',
        'local_media',
        'fb_media_ids',
        'fb_post_id',
        'fb_permalink_url',
        'status',
        'error',
        'published_at',

        // ⚠️ Si aún existen estas columnas en la DB, puedes dejarlas,
        // pero ya NO se usarán para guardar métricas:
        'alcance',
        'visualizaciones',
        'interacciones',
        'evidencia_path',
    ];

    protected $casts = [
        'local_media'     => 'array',
        'fb_media_ids'    => 'array',
        'published_at'    => 'datetime',

        // Estos casts quedan por compatibilidad si aún existen columnas:
        'alcance'         => 'integer',
        'visualizaciones' => 'integer',
        'interacciones'   => 'integer',
    ];

    /** Relaciones */
    public function page()
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Relación a métricas por ronda */
    public function metrics()
    {
        return $this->hasMany(MetaPostMetric::class, 'meta_post_id');
    }

    /** Helper para obtener una métrica por ronda (1 ó 2) */
    public function metricRound(int $round): ?MetaPostMetric
    {
        if ($this->relationLoaded('metrics')) {
            return $this->metrics->firstWhere('round', $round);
        }
        return $this->metrics()->where('round', $round)->first();
    }

    public function getFirstMetricAttribute(): ?MetaPostMetric
    {
        return $this->metricRound(1);
    }

    public function getSecondMetricAttribute(): ?MetaPostMetric
    {
        return $this->metricRound(2);
    }

    /** Fecha base efectiva */
    public function getEffectiveAtAttribute(): ?Carbon
    {
        return $this->published_at ?? $this->created_at;
    }

    /** Apertura R1 (36h) */
    public function getMetricsOpenAtAttribute(): ?Carbon
    {
        return $this->effective_at?->copy()->addHours(self::METRICS_OPEN_HOURS);
    }

    /** Reapertura R2 (30d) */
    public function getMetricsReopenAtAttribute(): ?Carbon
    {
        return $this->effective_at?->copy()->addDays(self::METRICS_REOPEN_DAYS);
    }

    /**
     * ¿Cuál ronda se puede registrar ahora? (1, 2 o null)
     * Reglas:
     *  - Antes de 36h: null
     *  - Desde 36h: si R1 no está completa ⇒ 1
     *  - R1 completa pero antes de 30d ⇒ null
     *  - Desde 30d: si R2 no está completa ⇒ 2
     *  - Ambas completas ⇒ null
     */
    public function getMetricsNextRoundAttribute(): ?int
    {
        $openAt   = $this->metrics_open_at;   // 36h
        $reopenAt = $this->metrics_reopen_at; // 30d
        if (!$openAt || now()->lt($openAt)) return null;

        $r1Complete = $this->first_metric?->is_complete ?? false;
        $r2Complete = $this->second_metric?->is_complete ?? false;

        if (!$r1Complete) return 1;
        if ($r1Complete && now()->lt($reopenAt)) return null;
        if (!$r2Complete) return 2;
        return null;
    }

    /** ¿Se puede registrar/actualizar métricas ahora mismo? */
    public function getCanRegisterMetricsAttribute(): bool
    {
        return (bool) $this->metrics_next_round;
    }

    /** Mensaje amigable para la UI (tooltip/label del botón) */
    public function getMetricsStateMessageAttribute(): string
    {
        $tz = config('app.timezone');
        $openAt   = $this->metrics_open_at;
        $reopenAt = $this->metrics_reopen_at;

        if ($openAt && now()->lt($openAt)) {
            return 'Disponible en ' . $openAt->diffForHumans(null, true);
        }

        $r1Complete = $this->first_metric?->is_complete ?? false;
        $r2Complete = $this->second_metric?->is_complete ?? false;

        if (!$r1Complete) {
            return 'Disponible (Ronda 1)';
        }

        if ($r1Complete && !$r2Complete) {
            if ($reopenAt && now()->lt($reopenAt)) {
                return 'Se habilitará el ' . $reopenAt->timezone($tz)->format('d/m/Y H:i');
            }
            return 'Disponible (Ronda 2)';
        }

        return 'Completado';
    }

    /** Scope páginas del usuario (como lo tenías) */
    public function scopeForUserPages(Builder $q, int $userId, bool $onlyActive = true): Builder
    {
        return $q->whereIn('meta_page_id', function ($sub) use ($userId, $onlyActive) {
            $sub->select('meta_pages.id')
                ->from('meta_pages')
                ->join('meta_page_user', 'meta_page_user.meta_page_id', '=', 'meta_pages.id')
                ->where('meta_page_user.user_id', $userId);

            if ($onlyActive) {
                $sub->where('meta_page_user.is_active', true);
            }
        });
    }
}
