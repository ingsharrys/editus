<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscripción de medios a una campaña con un enlace propio y certificado de publicación firmado:
 * - campaigns: token del enlace, si está abierto, datos del certificado y catálogo de piezas.
 * - campaign_participantes: cada medio inscrito con sus datos, logo, firma y aceptación de términos.
 * - meta_posts: programa, subprograma y tipo de pieza (llenan la tabla del certificado).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'inscripcion_token')) $t->string('inscripcion_token', 64)->nullable()->unique();
            if (!Schema::hasColumn('campaigns', 'inscripcion_activa')) $t->boolean('inscripcion_activa')->default(false);
            if (!Schema::hasColumn('campaigns', 'certificado')) $t->json('certificado')->nullable();
            if (!Schema::hasColumn('campaigns', 'piezas')) $t->json('piezas')->nullable();
        });

        if (!Schema::hasTable('campaign_participantes')) {
            Schema::create('campaign_participantes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('medio_nombre', 190)->nullable();
                $t->string('logo_path')->nullable();
                $t->string('representante', 190)->nullable();
                $t->string('documento_tipo', 20)->nullable();
                $t->string('documento', 40)->nullable();
                $t->string('cargo', 120)->nullable();
                $t->string('ciudad', 120)->nullable();
                $t->json('meta_page_ids')->nullable();
                $t->string('firma_path')->nullable();
                $t->string('firma_hash', 64)->nullable();
                $t->timestamp('acepto_terminos_at')->nullable();
                $t->string('terminos_hash', 64)->nullable();
                $t->timestamp('firmado_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 500)->nullable();
                $t->timestamps();
                $t->unique(['campaign_id', 'user_id']);
                $t->index(['user_id', 'firmado_at']);
            });
        }

        Schema::table('meta_posts', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_posts', 'programa')) $t->string('programa', 190)->nullable();
            if (!Schema::hasColumn('meta_posts', 'subprograma')) $t->string('subprograma', 190)->nullable();
            if (!Schema::hasColumn('meta_posts', 'tipo_pieza')) $t->string('tipo_pieza', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $t) {
            foreach (['programa', 'subprograma', 'tipo_pieza'] as $c) if (Schema::hasColumn('meta_posts', $c)) $t->dropColumn($c);
        });
        Schema::dropIfExists('campaign_participantes');
        Schema::table('campaigns', function (Blueprint $t) {
            if (Schema::hasColumn('campaigns', 'inscripcion_token')) {
                $t->dropUnique(['inscripcion_token']);
                $t->dropColumn('inscripcion_token');
            }
            foreach (['inscripcion_activa', 'certificado', 'piezas'] as $c) if (Schema::hasColumn('campaigns', $c)) $t->dropColumn($c);
        });
    }
};
