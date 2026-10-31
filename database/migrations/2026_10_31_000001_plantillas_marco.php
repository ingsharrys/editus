<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plantillas tipo "marco" (diseños de Canva): PNG del marco, medio al que pertenece y zonas de foto y textos. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plantillas_editor')) return;
        Schema::table('plantillas_editor', function (Blueprint $t) {
            if (!Schema::hasColumn('plantillas_editor', 'tipo')) $t->string('tipo', 10)->default('diseno')->after('nombre');
            if (!Schema::hasColumn('plantillas_editor', 'marco_path')) $t->string('marco_path')->nullable()->after('tipo');
            if (!Schema::hasColumn('plantillas_editor', 'medio_slug')) $t->string('medio_slug', 100)->nullable()->after('marco_path');
            if (!Schema::hasColumn('plantillas_editor', 'zonas')) $t->text('zonas')->nullable()->after('medio_slug');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('plantillas_editor')) return;
        Schema::table('plantillas_editor', function (Blueprint $t) {
            foreach (['zonas', 'medio_slug', 'marco_path', 'tipo'] as $c) {
                if (Schema::hasColumn('plantillas_editor', $c)) $t->dropColumn($c);
            }
        });
    }
};
