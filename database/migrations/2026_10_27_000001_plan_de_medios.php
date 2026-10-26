<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de medios por campaña:
 * - campaigns.plan_medios: la campaña usa inscripción de medios, certificado, cupos y subcampañas.
 * - campaign_subcampanas: subcampañas (p. ej. «Dengue») con su programa y subprograma del certificado.
 * - campaign_cupos: límite de piezas por tipo (Video Corto, Video Largo…), por defecto o por página.
 * - meta_posts.subcampana_id: a qué subcampaña pertenece cada publicación.
 * El catálogo de programas que había en campaigns.piezas se convierte en subcampañas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'plan_medios')) $t->boolean('plan_medios')->default(false);
        });

        if (!Schema::hasTable('campaign_subcampanas')) {
            Schema::create('campaign_subcampanas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->string('nombre', 120);
                $t->string('programa', 190)->nullable();
                $t->string('subprograma', 190)->nullable();
                $t->string('descripcion', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
                $t->index(['campaign_id', 'orden']);
            });
        }

        if (!Schema::hasTable('campaign_cupos')) {
            Schema::create('campaign_cupos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('meta_page_id')->nullable(); // null = cupo por defecto para cada página
                $t->string('tipo_pieza', 60);
                $t->unsignedInteger('cantidad');
                $t->timestamps();
                $t->index(['campaign_id', 'meta_page_id']);
            });
        }

        Schema::table('meta_posts', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_posts', 'subcampana_id')) $t->unsignedBigInteger('subcampana_id')->nullable()->index();
        });

        // Campañas que ya usaban inscripción o catálogo de programas → plan de medios, y su catálogo → subcampañas
        if (Schema::hasColumn('campaigns', 'piezas')) {
            foreach (DB::table('campaigns')->get(['id', 'piezas', 'inscripcion_activa']) as $c) {
                $piezas = json_decode((string) $c->piezas, true) ?: [];
                if ($c->inscripcion_activa || $piezas) DB::table('campaigns')->where('id', $c->id)->update(['plan_medios' => true]);
                if (!$piezas || DB::table('campaign_subcampanas')->where('campaign_id', $c->id)->exists()) continue;
                foreach (array_values($piezas) as $i => $f) {
                    $prog = trim((string) ($f['programa'] ?? ''));
                    $sub = trim((string) ($f['subprograma'] ?? ''));
                    if ($prog === '') continue;
                    $id = DB::table('campaign_subcampanas')->insertGetId([
                        'campaign_id' => $c->id, 'nombre' => mb_substr($sub ?: $prog, 0, 120), 'programa' => $prog, 'subprograma' => $sub ?: null,
                        'is_active' => true, 'orden' => $i, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    // Publicaciones ya clasificadas con ese programa y subprograma
                    DB::table('meta_posts')->where('campaign_id', $c->id)->whereNull('subcampana_id')->where('programa', $prog)
                        ->where(fn($q) => $sub === '' ? $q->whereNull('subprograma')->orWhere('subprograma', '') : $q->where('subprograma', $sub))
                        ->update(['subcampana_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $t) {
            if (Schema::hasColumn('meta_posts', 'subcampana_id')) {
                $t->dropIndex(['subcampana_id']);
                $t->dropColumn('subcampana_id');
            }
        });
        Schema::dropIfExists('campaign_cupos');
        Schema::dropIfExists('campaign_subcampanas');
        Schema::table('campaigns', function (Blueprint $t) {
            if (Schema::hasColumn('campaigns', 'plan_medios')) $t->dropColumn('plan_medios');
        });
    }
};
