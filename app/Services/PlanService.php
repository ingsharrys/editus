<?php

namespace App\Services;

use App\Models\MetaPage;
use App\Models\PagoSuscripcion;
use App\Models\Suscripcion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Planes y límites: qué puede hacer cada cliente según su suscripción.
 * Administradores y equipo interno (exento_planes) no tienen límites.
 */
class PlanService
{
    public const PERIODOS = ['mensual', 'anual'];

    public function planes(): array
    {
        return (array) config('planes.planes', []);
    }

    public function precio(string $plan, string $periodo): int
    {
        return (int) ($this->planes()[$plan][$periodo] ?? 0);
    }

    public function habilitado(): bool
    {
        return Schema::hasTable('suscripciones');
    }

    public function sinLimites(?User $user): bool
    {
        return !$user || !$this->habilitado() || $user->sinLimitesDePlan();
    }

    public function suscripcion(User $user): ?Suscripcion
    {
        return $this->habilitado() ? Suscripcion::where('user_id', $user->id)->first() : null;
    }

    /** Límites efectivos. null = sin plan activo (no puede publicar ni transmitir). */
    public function limites(?User $user): ?array
    {
        if ($this->sinLimites($user)) {
            return ['plan' => 'sin_limites', 'nombre' => 'Sin límites', 'paginas' => PHP_INT_MAX, 'camaras' => 50, 'plantillas_logo' => true, 'sitios' => PHP_INT_MAX];
        }
        $s = $this->suscripcion($user);
        if (!$s || !$s->activa()) return null;
        return ['plan' => $s->plan, 'nombre' => $s->nombrePlan()] + array_intersect_key($s->config(), array_flip(['paginas', 'camaras', 'plantillas_logo', 'sitios']));
    }

    /** Máximo de cámaras de una transmisión según el plan de quien la creó en la web (null = sin límite). */
    public function camarasDe(\App\Models\TransmisionEnVivo $t): ?int
    {
        $userId = (int) ($t->user_id ?? 0);
        if (!$userId) return null;
        $user = User::find($userId);
        if ($this->sinLimites($user)) return null;
        return (int) ($this->limites($user)['camaras'] ?? 1);
    }

    /** Páginas conectadas por el usuario (con token activo), en el orden en que las conectó. */
    public function paginasConectadas(User $user): Collection
    {
        return MetaPage::query()
            ->join('meta_page_user', 'meta_page_user.meta_page_id', '=', 'meta_pages.id')
            ->where('meta_page_user.user_id', $user->id)->where('meta_page_user.is_active', 1)->whereNotNull('meta_page_user.page_access_token')
            ->orderBy('meta_page_user.created_at')->orderBy('meta_pages.id')
            ->select('meta_pages.*')->get()->unique('id')->values();
    }

    /**
     * Páginas donde el cliente puede publicar: las que eligió para su plan (o, si no eligió,
     * las primeras que conectó), hasta el máximo del plan. Sin límites: null (todas).
     */
    public function paginasPermitidas(User $user): ?Collection
    {
        if ($this->sinLimites($user)) return null;
        $lim = $this->limites($user);
        if (!$lim) return collect();
        $conectadas = $this->paginasConectadas($user);
        $elegidas = array_map('intval', (array) ($this->suscripcion($user)?->paginas ?? []));
        $lista = $elegidas ? $conectadas->filter(fn($p) => in_array((int) $p->id, $elegidas, true)) : $conectadas;
        return $lista->take((int) $lim['paginas'])->values();
    }

    /** Devuelve null si puede publicar en esas páginas (meta_pages.id); si no, el motivo. */
    public function validarPaginas(?User $user, array $metaPageIds): ?string
    {
        if ($this->sinLimites($user)) return null;
        $lim = $this->limites($user);
        if (!$lim) return 'Necesitas un plan activo para publicar o transmitir. Elige tu plan en «Mi suscripción».';
        $permitidas = $this->paginasPermitidas($user)->pluck('id')->map(fn($v) => (int) $v)->all();
        foreach ($metaPageIds as $id) {
            if (!in_array((int) $id, $permitidas, true)) {
                return "Tu plan {$lim['nombre']} permite publicar en {$lim['paginas']} página(s). Elige cuáles en «Mi suscripción»; esta selección incluye una página fuera de tu plan.";
            }
        }
        return null;
    }

    /** Guarda las páginas elegidas por el cliente para su plan (solo entre las que conectó). */
    public function elegirPaginas(User $user, array $metaPageIds): void
    {
        $s = $this->suscripcion($user);
        if (!$s) return;
        $max = (int) ($s->config()['paginas'] ?? 0);
        $conectadas = $this->paginasConectadas($user)->pluck('id')->map(fn($v) => (int) $v)->all();
        $ids = array_slice(array_values(array_intersect(array_map('intval', $metaPageIds), $conectadas)), 0, $max);
        $s->fill(['paginas' => $ids])->save();
    }

    /**
     * Aplica un pago aprobado: activa o extiende la suscripción una sola vez (idempotente).
     * Mismo plan y vigente: se suma el periodo al vencimiento. Otro plan: empieza hoy.
     */
    public function aplicarPago(PagoSuscripcion $pago): Suscripcion
    {
        return DB::transaction(function () use ($pago) {
            $pago = PagoSuscripcion::whereKey($pago->id)->lockForUpdate()->first();
            $s = Suscripcion::firstOrNew(['user_id' => $pago->user_id]);
            if ($pago->aplicado_en) return $s;
            $dias = $pago->periodo === 'anual' ? (int) config('planes.dias_anio', 365) : (int) config('planes.dias_mes', 30);
            $base = ($s->exists && $s->activa() && $s->plan === $pago->plan) ? $s->vence_en->copy() : now();
            if (!$s->exists || !$s->activa() || $s->plan !== $pago->plan) $s->inicia_en = now();
            $s->fill(['plan' => $pago->plan, 'periodo' => $pago->periodo, 'vence_en' => $base->addDays($dias)])->save();
            if (!$s->licencia_prefijo) $this->generarLicencia($s);
            $pago->fill(['aplicado_en' => now()])->save();
            return $s->fresh();
        });
    }

    /**
     * Crea (o reemplaza) la llave de licencia del plugin. Devuelve la llave completa UNA vez;
     * en la base solo queda su hash (sha256). Formato: ss_<prefijo>_<secreto>.
     */
    public function generarLicencia(Suscripcion $s): string
    {
        do {
            $prefijo = Str::lower(Str::random(12));
        } while (Suscripcion::where('licencia_prefijo', $prefijo)->exists());
        $llave = 'ss_' . $prefijo . '_' . Str::random(40);
        $s->fill(['licencia_prefijo' => $prefijo, 'licencia_hash' => hash('sha256', $llave), 'licencia_sitios' => [], 'licencia_creada_en' => now()])->save();
        session()->flash('licencia_nueva', $llave);
        return $llave;
    }

    /** Separa una llave ss_<prefijo>_<secreto>; null si no tiene el formato. */
    public static function prefijoDe(string $llave): ?string
    {
        return preg_match('/^ss_([a-z0-9]{12})_[A-Za-z0-9]{40}$/', $llave, $m) ? $m[1] : null;
    }
}
