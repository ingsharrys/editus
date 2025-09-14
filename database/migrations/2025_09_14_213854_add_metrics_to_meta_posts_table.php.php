<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->unsignedInteger('alcance')->nullable()->after('status');
            $table->unsignedInteger('visualizaciones')->nullable()->after('alcance');
            $table->unsignedInteger('interacciones')->nullable()->after('visualizaciones');
            $table->string('evidencia_path')->nullable()->after('interacciones');
        });
    }
    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->dropColumn(['alcance', 'visualizaciones', 'interacciones', 'evidencia_path']);
        });
    }
};
