<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un medio de comunicación no es de una sola «clase»: tiene varios canales (página web, Facebook, Instagram,
 * TikTok, YouTube, emisora, televisión, impreso) y en cada red social publica con o sin pauta.
 * campaign_medios.canales: {"web": {"pauta": false}, "facebook": {"pauta": true}, …}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_medios')) return;
        if (!Schema::hasColumn('campaign_medios', 'canales')) {
            Schema::table('campaign_medios', function (Blueprint $t) {
                $t->json('canales')->nullable()->after('clase');
            });
        }
        // Los medios que ya tenían una clase quedan con el canal equivalente
        $equivale = ['web' => 'web', 'digital' => 'web', 'emisora' => 'radio', 'tv' => 'tv', 'impreso' => 'impreso', 'redes' => 'facebook'];
        DB::table('campaign_medios')->whereNull('canales')->whereNotNull('clase')->orderBy('id')->each(function ($m) use ($equivale) {
            if (isset($equivale[$m->clase])) {
                DB::table('campaign_medios')->where('id', $m->id)->update(['canales' => json_encode([$equivale[$m->clase] => ['pauta' => false]])]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_medios') && Schema::hasColumn('campaign_medios', 'canales')) {
            Schema::table('campaign_medios', function (Blueprint $t) {
                $t->dropColumn('canales');
            });
        }
    }
};
