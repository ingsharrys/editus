<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Diagnósticos estratégicos de la IA (por campaña o de la vista general), con su plan de la semana. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('diagnosticos_ia')) return;
        Schema::create('diagnosticos_ia', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->nullable()->constrained('campanas')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('enfoque', 20);
            $t->json('ambito')->nullable();
            $t->json('resultado');
            $t->string('modelo', 60)->nullable();
            $t->timestamps();
            $t->index(['campana_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos_ia');
    }
};
