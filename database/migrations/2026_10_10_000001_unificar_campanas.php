<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Campañas unificadas: la tabla `campaigns` (la que etiqueta cada publicación) pasa a
 * ser la ÚNICA campaña. Gana tipo, contexto para la IA, territorio, fechas y los MEDIOS
 * donde se publica. Lo que antes era una "campaña de Inteligencia" (`campanas`, con sus
 * temas, lecturas e informes) queda como el PERFIL DE ANÁLISIS de cada campaña, enlazado
 * 1 a 1 por `campanas.campaign_id`. Sus páginas se calculan desde los medios de la campaña
 * (origen = medio) más las que se agreguen a mano (origen = manual).
 *
 * Datos existentes: cada campaña de Inteligencia se enlaza con la campaña de publicación
 * del mismo nombre o se crea una nueva; cada campaña de publicación (incluida Esnoticia,
 * que no se modifica) recibe su perfil de análisis. No se borra nada.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'tipo')) $t->string('tipo', 20)->default('institucional')->after('description');
            if (!Schema::hasColumn('campaigns', 'contexto')) $t->text('contexto')->nullable()->after('tipo');
            if (!Schema::hasColumn('campaigns', 'territorio')) $t->string('territorio', 120)->nullable()->after('contexto');
            if (!Schema::hasColumn('campaigns', 'starts_on')) $t->date('starts_on')->nullable()->after('territorio');
            if (!Schema::hasColumn('campaigns', 'ends_on')) $t->date('ends_on')->nullable()->after('starts_on');
            if (!Schema::hasColumn('campaigns', 'medios')) $t->json('medios')->nullable()->after('ends_on'); // ["opanoticias", ...]; null en la de sistema = todos
        });

        if (Schema::hasTable('campanas')) {
            Schema::table('campanas', function (Blueprint $t) {
                if (!Schema::hasColumn('campanas', 'campaign_id')) $t->foreignId('campaign_id')->nullable()->after('id')->unique()->constrained('campaigns')->cascadeOnDelete();
            });
            Schema::table('campana_pagina', function (Blueprint $t) {
                if (!Schema::hasColumn('campana_pagina', 'origen')) $t->string('origen', 10)->default('manual'); // medio | manual
            });
            $this->fusionar();
        }
    }

    /** Enlaza los datos existentes (idempotente). */
    private function fusionar(): void
    {
        $ahora = now();
        $medios = array_keys((array) config('services.editus.medios', []));

        // 1) Campañas de Inteligencia sin campaña de publicación → enlazar por nombre o crear
        foreach (DB::table('campanas')->whereNull('campaign_id')->get() as $c) {
            $existente = DB::table('campaigns')->whereRaw('LOWER(name) = ?', [mb_strtolower($c->nombre)])->first();
            if ($existente && DB::table('campanas')->where('campaign_id', $existente->id)->exists()) $existente = null;
            if ($existente) {
                $id = $existente->id;
                DB::table('campaigns')->where('id', $id)->update(array_filter([
                    'tipo' => $existente->is_system ? 'sistema' : 'politica',
                    'contexto' => $existente->contexto ?? $c->descripcion,
                    'territorio' => $existente->territorio ?? $c->territorio,
                    'starts_on' => $existente->starts_on ?? $c->desde,
                    'ends_on' => $existente->ends_on ?? $c->hasta,
                ], fn($v) => $v !== null));
            } else {
                $nombre = $c->nombre;
                if (DB::table('campaigns')->where('name', $nombre)->exists()) $nombre .= ' (análisis)';
                $base = Str::slug($nombre) ?: 'campana';
                $slug = $base; $i = 2;
                while (DB::table('campaigns')->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
                $id = DB::table('campaigns')->insertGetId([
                    'name' => $nombre, 'slug' => $slug, 'description' => Str::limit((string) $c->descripcion, 490, ''),
                    'tipo' => 'politica', 'contexto' => $c->descripcion, 'territorio' => $c->territorio,
                    'starts_on' => $c->desde, 'ends_on' => $c->hasta, 'is_system' => false, 'is_active' => (bool) $c->activa,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
            // Medios de la campaña: los de sus páginas actuales (las páginas sin medio quedan como manuales)
            $slugs = DB::table('campana_pagina')->join('meta_pages', 'meta_pages.id', '=', 'campana_pagina.meta_page_id')
                ->where('campana_pagina.campana_id', $c->id)->whereNotNull('meta_pages.medio_slug')->distinct()->pluck('meta_pages.medio_slug')->all();
            $actual = json_decode((string) DB::table('campaigns')->where('id', $id)->value('medios'), true) ?: [];
            DB::table('campaigns')->where('id', $id)->update(['medios' => json_encode(array_values(array_unique(array_merge($actual, $slugs))))]);
            DB::table('campanas')->where('id', $c->id)->update(['campaign_id' => $id]);
        }

        // 2) Campañas de publicación sin perfil de análisis → crearlo (Esnoticia incluida, sin modificarla)
        foreach (DB::table('campaigns')->get() as $cp) {
            if (DB::table('campanas')->where('campaign_id', $cp->id)->exists()) continue;
            if ($cp->is_system && $cp->tipo !== 'sistema') DB::table('campaigns')->where('id', $cp->id)->update(['tipo' => 'sistema']);
            DB::table('campanas')->insert([
                'campaign_id' => $cp->id, 'nombre' => $cp->name, 'descripcion' => $cp->contexto ?? $cp->description,
                'territorio' => $cp->territorio, 'desde' => $cp->starts_on, 'hasta' => $cp->ends_on, 'activa' => (bool) $cp->is_active,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        // 3) Páginas de cada perfil según los medios de su campaña
        try {
            app(\App\Services\CampanasService::class)->sincronizarTodas();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[campañas] sincronización inicial', ['err' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campanas') && Schema::hasColumn('campanas', 'campaign_id')) {
            Schema::table('campanas', function (Blueprint $t) {
                $t->dropConstrainedForeignId('campaign_id');
            });
        }
        if (Schema::hasTable('campana_pagina') && Schema::hasColumn('campana_pagina', 'origen')) {
            Schema::table('campana_pagina', fn(Blueprint $t) => $t->dropColumn('origen'));
        }
        Schema::table('campaigns', function (Blueprint $t) {
            foreach (['tipo', 'contexto', 'territorio', 'starts_on', 'ends_on', 'medios'] as $c) {
                if (Schema::hasColumn('campaigns', $c)) $t->dropColumn($c);
            }
        });
    }
};
