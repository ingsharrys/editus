<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Productos (piezas) del plan de medios e informes de métricas:
 * - campaign_subcampanas = tipo de publicación (Videos largos, Banner 1600x185, En vivos…), con lo que necesita el informe.
 * - campaign_programas: programas del contrato (p. ej. «2-1 Salud infantil y adolescencia») y sus subprogramas.
 * - campaign_productos: cada pieza (control de productos): tipo, programa, estado, actas, muestra, link…
 * - campaign_producto_publicaciones: publicaciones de una pieza hechas fuera de editus (páginas web, otros medios)
 *   o datos extra (captura, métricas corregidas) de una publicación de editus.
 * - meta_posts.producto_id: la pieza que se publicó.
 * - campaigns.informe: membrete y textos del informe.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaign_subcampanas', function (Blueprint $t) {
            foreach ([
                'etiqueta' => fn() => $t->string('etiqueta', 80)->nullable(),          // «Video Largo» → «Video Largo No. 1»
                'clase' => fn() => $t->string('clase', 20)->default('redes'),         // redes | web | envivo | audio | impreso
                'tipo' => fn() => $t->string('tipo', 40)->nullable(),                 // IMAGEN, VIDEO, AUDIO…
                'formato' => fn() => $t->string('formato', 80)->nullable(),           // 1600 x 185 px
                'unidad' => fn() => $t->string('unidad', 120)->nullable(),            // «video largo», «banner de 1.600x185 px»
                'plantilla' => fn() => $t->string('plantilla', 500)->nullable(),      // descripción de cada pieza en el informe
                'titulo_informe' => fn() => $t->string('titulo_informe', 120)->nullable(),
            ] as $col => $crear) {
                if (!Schema::hasColumn('campaign_subcampanas', $col)) $crear();
            }
        });

        if (!Schema::hasTable('campaign_programas')) {
            Schema::create('campaign_programas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->string('codigo', 20);                 // 2-1
                $t->string('nombre', 190);                // Salud infantil y adolescencia
                $t->json('subprogramas')->nullable();     // ["Vacunación", "PAI", "Nutrición"]
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
                $t->index(['campaign_id', 'orden']);
            });
        }

        if (!Schema::hasTable('campaign_productos')) {
            Schema::create('campaign_productos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('subcampana_id')->nullable()->index();
                $t->unsignedBigInteger('programa_id')->nullable()->index();
                $t->string('subprograma', 190)->nullable();
                $t->unsignedInteger('numero')->default(0);
                $t->string('nombre', 255);
                $t->text('descripcion')->nullable();
                $t->string('responsable', 120)->nullable();
                $t->date('fecha_solicitud')->nullable();
                $t->date('fecha_entrega')->nullable();
                $t->date('fecha_publicacion')->nullable();
                $t->date('fecha_fin')->nullable();
                $t->string('estado', 40)->default('Pendiente');
                $t->string('acta_aprobacion', 60)->nullable();
                $t->string('acta_publicacion', 60)->nullable();
                $t->string('link_archivo', 500)->nullable();
                $t->string('muestra_path')->nullable();
                $t->text('observaciones')->nullable();
                $t->timestamps();
                $t->index(['campaign_id', 'subcampana_id', 'numero']);
            });
        }

        if (!Schema::hasTable('campaign_producto_publicaciones')) {
            Schema::create('campaign_producto_publicaciones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('producto_id')->constrained('campaign_productos')->cascadeOnDelete();
                $t->unsignedBigInteger('meta_post_id')->nullable()->index(); // datos extra de una publicación de editus
                $t->unsignedBigInteger('meta_page_id')->nullable();
                $t->string('medio', 190)->nullable();
                $t->string('url', 1000)->nullable();
                $t->unsignedBigInteger('alcance')->nullable();
                $t->unsignedBigInteger('visualizaciones')->nullable();
                $t->unsignedBigInteger('interacciones')->nullable();
                $t->date('fecha')->nullable();
                $t->string('evidencia_path')->nullable();
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
            });
        }

        Schema::table('meta_posts', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_posts', 'producto_id')) $t->unsignedBigInteger('producto_id')->nullable()->index();
        });

        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'informe')) $t->json('informe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (Schema::hasColumn('campaigns', 'informe')) $t->dropColumn('informe');
        });
        Schema::table('meta_posts', function (Blueprint $t) {
            if (Schema::hasColumn('meta_posts', 'producto_id')) {
                $t->dropIndex(['producto_id']);
                $t->dropColumn('producto_id');
            }
        });
        Schema::dropIfExists('campaign_producto_publicaciones');
        Schema::dropIfExists('campaign_productos');
        Schema::dropIfExists('campaign_programas');
        Schema::table('campaign_subcampanas', function (Blueprint $t) {
            foreach (['etiqueta', 'clase', 'tipo', 'formato', 'unidad', 'plantilla', 'titulo_informe'] as $c) {
                if (Schema::hasColumn('campaign_subcampanas', $c)) $t->dropColumn($c);
            }
        });
    }
};
