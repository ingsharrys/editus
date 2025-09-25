<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meta_post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_post_id')->constrained('meta_posts')->cascadeOnDelete();
            $table->unsignedTinyInteger('round')->index(); // 1 ó 2
            $table->unsignedBigInteger('alcance')->nullable();
            $table->unsignedBigInteger('visualizaciones')->nullable();
            $table->unsignedBigInteger('interacciones')->nullable();
            $table->string('evidencia_path')->nullable();
            $table->timestamps();

            $table->unique(['meta_post_id', 'round']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_post_metrics');
    }
};
