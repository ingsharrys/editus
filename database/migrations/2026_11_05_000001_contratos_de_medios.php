<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contratos de los medios de una campaña:
 *  - campaign_contrato_tipos: los modelos de contrato (texto con variables) de la campaña.
 *  - campaign_medios: el listado de medios de comunicación, cada uno con su tipo de contrato.
 *  - campaign_contratos: cada contrato firmado, con su número consecutivo, los datos del encabezado,
 *    los documentos (RUT, certificado bancario), la firma y el texto tal como se firmó.
 *  - campaigns.contratos: numeración (prefijo y número inicial).
 *  - campaign_productos.participante_id: piezas que reportó el propio medio (TikTok, banners).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_contrato_tipos')) {
            Schema::create('campaign_contrato_tipos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->string('nombre', 190);
                $t->string('clase_medio', 30)->nullable();   // digital, web, emisora, redes…
                $t->longText('cuerpo')->nullable();          // HTML con variables {medio}, {representante}…
                $t->boolean('activo')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('campaign_medios')) {
            Schema::create('campaign_medios', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->string('nombre', 190);
                $t->string('clase', 30)->nullable();
                $t->unsignedBigInteger('tipo_id')->nullable()->index();
                $t->string('correo', 190)->nullable();
                $t->string('notas', 500)->nullable();
                $t->unsignedBigInteger('participante_id')->nullable()->index(); // quién lo eligió al inscribirse
                $t->boolean('activo')->default(true);
                $t->unsignedInteger('orden')->default(0);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('campaign_contratos')) {
            Schema::create('campaign_contratos', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('medio_id')->index();
                $t->unsignedBigInteger('participante_id')->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('tipo_id')->nullable();
                $t->unsignedInteger('numero');
                $t->string('codigo', 80);
                $t->json('datos')->nullable();
                $t->string('logo_path')->nullable();
                $t->string('membrete_path')->nullable();
                $t->string('rut_path')->nullable();
                $t->string('certificado_bancario_path')->nullable();
                $t->string('firma_path')->nullable();
                $t->string('firma_hash', 64)->nullable();
                $t->longText('cuerpo')->nullable();          // el texto tal como se firmó
                $t->string('cuerpo_hash', 64)->nullable();
                $t->string('pdf_path')->nullable();
                $t->timestamp('firmado_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 500)->nullable();
                $t->timestamp('enviado_at')->nullable();
                $t->string('envio_error', 500)->nullable();
                $t->timestamp('anulado_at')->nullable();
                $t->timestamps();
                $t->unique(['campaign_id', 'numero']);
            });
        }
        if (Schema::hasTable('campaigns') && !Schema::hasColumn('campaigns', 'contratos')) {
            Schema::table('campaigns', function (Blueprint $t) {
                $t->json('contratos')->nullable();
            });
        }
        if (Schema::hasTable('campaign_productos') && !Schema::hasColumn('campaign_productos', 'participante_id')) {
            Schema::table('campaign_productos', function (Blueprint $t) {
                $t->unsignedBigInteger('participante_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_contratos');
        Schema::dropIfExists('campaign_medios');
        Schema::dropIfExists('campaign_contrato_tipos');
        if (Schema::hasColumn('campaigns', 'contratos')) Schema::table('campaigns', fn(Blueprint $t) => $t->dropColumn('contratos'));
        if (Schema::hasColumn('campaign_productos', 'participante_id')) Schema::table('campaign_productos', function (Blueprint $t) {
            $t->dropIndex(['participante_id']);
            $t->dropColumn('participante_id');
        });
    }
};
