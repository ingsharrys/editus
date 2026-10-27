<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcampaña = tema (p. ej. «Dengue»); dentro de ella se asignan los tipos de publicación.
 * - campaign_tipos: tipos de publicación de la campaña (Videos largos, Banner 1600x185, En vivos…)
 *   con lo que necesita el informe (nombre singular, clase, formato, unidad, plantilla, título).
 * - campaign_subcampana_tipo: qué tipos lleva cada subcampaña y cuántas piezas se planean.
 * - campaign_subcampanas.programa_id: programa del contrato de la subcampaña.
 * - campaign_productos.tipo_id: tipo de publicación de cada pieza.
 * Las subcampañas que se crearon como tipos de publicación (Videos largos, Cuñas…) se convierten en tipos.
 */
return new class extends Migration {
    private const PALABRAS_TIPO = '/(video|banner|post|podcast|cu[ñn]a|vivo|impreso|reel|historia|carrusel|nota web|influencer|redes sociales)/iu';

    public function up(): void
    {
        if (!Schema::hasTable('campaign_tipos')) {
            Schema::create('campaign_tipos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->string('nombre', 120);
                $t->string('etiqueta', 80)->nullable();
                $t->string('clase', 20)->default('redes');
                $t->string('tipo', 40)->nullable();
                $t->string('formato', 80)->nullable();
                $t->string('unidad', 120)->nullable();
                $t->string('plantilla', 500)->nullable();
                $t->string('titulo_informe', 120)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
                $t->index(['campaign_id', 'orden']);
            });
        }
        if (!Schema::hasTable('campaign_subcampana_tipo')) {
            Schema::create('campaign_subcampana_tipo', function (Blueprint $t) {
                $t->id();
                $t->foreignId('subcampana_id')->constrained('campaign_subcampanas')->cascadeOnDelete();
                $t->foreignId('tipo_id')->constrained('campaign_tipos')->cascadeOnDelete();
                $t->unsignedInteger('cantidad')->nullable(); // piezas planeadas de ese tipo en la subcampaña
                $t->timestamps();
                $t->unique(['subcampana_id', 'tipo_id']);
            });
        }
        Schema::table('campaign_subcampanas', function (Blueprint $t) {
            if (!Schema::hasColumn('campaign_subcampanas', 'programa_id')) $t->unsignedBigInteger('programa_id')->nullable()->index();
        });
        Schema::table('campaign_productos', function (Blueprint $t) {
            if (!Schema::hasColumn('campaign_productos', 'tipo_id')) $t->unsignedBigInteger('tipo_id')->nullable()->index();
        });

        $this->convertir();
    }

    /** Las subcampañas que son en realidad tipos de publicación pasan a campaign_tipos (con sus piezas). */
    private function convertir(): void
    {
        if (!Schema::hasColumn('campaign_subcampanas', 'etiqueta')) return;
        $conProductos = Schema::hasTable('campaign_productos');
        foreach (DB::table('campaign_subcampanas')->orderBy('campaign_id')->orderBy('orden')->orderBy('id')->get() as $s) {
            $tienePiezas = $conProductos && DB::table('campaign_productos')->where('subcampana_id', $s->id)->exists();
            $esTipo = $s->etiqueta !== null || $tienePiezas || preg_match(self::PALABRAS_TIPO, (string) $s->nombre);
            if (!$esTipo) continue;
            $tipo = DB::table('campaign_tipos')->where('campaign_id', $s->campaign_id)->where('nombre', $s->nombre)->value('id');
            if (!$tipo) {
                $tipo = DB::table('campaign_tipos')->insertGetId([
                    'campaign_id' => $s->campaign_id, 'nombre' => $s->nombre, 'etiqueta' => $s->etiqueta, 'clase' => $s->clase ?: 'redes',
                    'tipo' => $s->tipo, 'formato' => $s->formato, 'unidad' => $s->unidad, 'plantilla' => $s->plantilla, 'titulo_informe' => $s->titulo_informe,
                    'is_active' => (bool) $s->is_active, 'orden' => (int) $s->orden, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($conProductos) DB::table('campaign_productos')->where('subcampana_id', $s->id)->update(['tipo_id' => $tipo, 'subcampana_id' => null]);
            DB::table('meta_posts')->where('subcampana_id', $s->id)->update(['subcampana_id' => null]);
            DB::table('campaign_subcampanas')->where('id', $s->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('campaign_productos', function (Blueprint $t) {
            if (Schema::hasColumn('campaign_productos', 'tipo_id')) {
                $t->dropIndex(['tipo_id']);
                $t->dropColumn('tipo_id');
            }
        });
        Schema::table('campaign_subcampanas', function (Blueprint $t) {
            if (Schema::hasColumn('campaign_subcampanas', 'programa_id')) {
                $t->dropIndex(['programa_id']);
                $t->dropColumn('programa_id');
            }
        });
        Schema::dropIfExists('campaign_subcampana_tipo');
        Schema::dropIfExists('campaign_tipos');
    }
};
