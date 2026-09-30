<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de la app móvil del editor (sección Redes):
 *  - meta_pages.visible_en_editor: si la página se ofrece en la app.
 *  - meta_pages.medio_slug: medio (sitio web) al que pertenece la página,
 *    para que la app preseleccione de qué nota va el enlace.
 *  - plantillas_editor: plantillas de imagen (logo, colores, pie, hashtag).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_pages', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_pages', 'visible_en_editor')) {
                $t->boolean('visible_en_editor')->default(true)->after('tasks');
            }
            if (!Schema::hasColumn('meta_pages', 'medio_slug')) {
                $t->string('medio_slug', 100)->nullable()->after('visible_en_editor');
            }
        });

        if (!Schema::hasTable('plantillas_editor')) {
            Schema::create('plantillas_editor', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 120);
                $t->string('logo_path')->nullable();          // PNG subido (storage/app/public)
                $t->string('logo_texto', 60)->nullable();     // Logo en texto: "OPA Noticias"
                $t->boolean('logo_tintar')->default(true);    // Pintar el PNG con el color elegido
                $t->string('etiqueta', 40)->nullable();       // Sección por defecto: NOTICIAS
                $t->string('pie', 80)->nullable();            // Opanoticias.com
                $t->string('hashtag', 60)->nullable();        // #EsNoticia
                $t->string('color_titulo', 7)->default('#FFFFFF');
                $t->string('color_logo', 7)->default('#FFFFFF');
                $t->string('color_etiqueta', 7)->default('#C8102E');
                $t->boolean('predeterminada')->default(false);
                $t->boolean('activa')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plantillas_editor');
        Schema::table('meta_pages', function (Blueprint $t) {
            foreach (['visible_en_editor', 'medio_slug'] as $c) {
                if (Schema::hasColumn('meta_pages', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
