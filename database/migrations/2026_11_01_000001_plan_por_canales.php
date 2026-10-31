<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de medios por canales (ficha técnica): cada tipo de publicación es de un canal (radio, medios digitales
 * orgánico, Facebook / Instagram con pauta, TikTok), con su meta de alcance, valor unitario y característica.
 * Las piezas con pauta guardan el presupuesto asignado y el gastado. Las emisoras registran y firman sus cuñas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_tipos')) {
            Schema::table('campaign_tipos', function (Blueprint $t) {
                if (!Schema::hasColumn('campaign_tipos', 'canal')) $t->string('canal', 20)->nullable()->after('clase');
                if (!Schema::hasColumn('campaign_tipos', 'meta_alcance')) $t->unsignedInteger('meta_alcance')->nullable()->after('canal');
                if (!Schema::hasColumn('campaign_tipos', 'valor_unitario')) $t->decimal('valor_unitario', 14, 2)->nullable()->after('meta_alcance');
                if (!Schema::hasColumn('campaign_tipos', 'caracteristica')) $t->text('caracteristica')->nullable()->after('valor_unitario');
            });
        }
        if (Schema::hasTable('campaign_productos')) {
            Schema::table('campaign_productos', function (Blueprint $t) {
                if (!Schema::hasColumn('campaign_productos', 'pauta_asignada')) $t->decimal('pauta_asignada', 14, 2)->nullable();
                if (!Schema::hasColumn('campaign_productos', 'pauta_gastada')) $t->decimal('pauta_gastada', 14, 2)->nullable();
                if (!Schema::hasColumn('campaign_productos', 'pauta_desde')) $t->date('pauta_desde')->nullable();
                if (!Schema::hasColumn('campaign_productos', 'pauta_hasta')) $t->date('pauta_hasta')->nullable();
            });
        }
        if (!Schema::hasTable('campaign_emisiones') && Schema::hasTable('campaign_participantes')) {
            Schema::create('campaign_emisiones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
                $t->foreignId('participante_id')->constrained('campaign_participantes')->cascadeOnDelete(); // la emisora
                $t->unsignedBigInteger('subcampana_id')->nullable()->index();
                $t->unsignedBigInteger('tipo_id')->nullable()->index();
                $t->unsignedInteger('cantidad');              // cuñas / espacios emitidos
                $t->date('fecha_desde');
                $t->date('fecha_hasta')->nullable();
                $t->string('programa', 190)->nullable();     // programa o franja
                $t->string('horario', 120)->nullable();
                $t->string('soporte_path')->nullable();      // certificación de la emisora, audio o pauta
                $t->text('observaciones')->nullable();
                $t->string('firma_path')->nullable();
                $t->string('firma_hash', 64)->nullable();
                $t->timestamp('firmado_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
                $t->index(['campaign_id', 'subcampana_id', 'tipo_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_emisiones');
        if (Schema::hasTable('campaign_productos')) {
            Schema::table('campaign_productos', function (Blueprint $t) {
                foreach (['pauta_asignada', 'pauta_gastada', 'pauta_desde', 'pauta_hasta'] as $c) if (Schema::hasColumn('campaign_productos', $c)) $t->dropColumn($c);
            });
        }
        if (Schema::hasTable('campaign_tipos')) {
            Schema::table('campaign_tipos', function (Blueprint $t) {
                foreach (['canal', 'meta_alcance', 'valor_unitario', 'caracteristica'] as $c) if (Schema::hasColumn('campaign_tipos', $c)) $t->dropColumn($c);
            });
        }
    }
};
