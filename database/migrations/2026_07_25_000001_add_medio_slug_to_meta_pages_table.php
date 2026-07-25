<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula cada página de Facebook con un medio del sistema de noticias
 * (backend.esnoticia.org). Cuando un artículo se publica en un medio,
 * el endpoint /api/articulos/publicar busca las páginas cuyo medio_slug
 * coincida y publica en ellas.
 *
 * Ejemplos de slug: opanoticias, depindo, labalsa, elcivico, prensayuma,
 * greengonews, neiva24, lasurco, latinreds, neivaaldia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_pages', function (Blueprint $table) {
            $table->string('medio_slug', 100)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('meta_pages', function (Blueprint $table) {
            $table->dropIndex(['medio_slug']);
            $table->dropColumn('medio_slug');
        });
    }
};
