<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Métricas diarias por página (series de tiempo)
        Schema::create('meta_page_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);      // page_impressions
            $table->unsignedBigInteger('reach')->default(0);            // page_impressions_unique
            $table->unsignedBigInteger('engagements')->default(0);      // page_post_engagements
            $table->unsignedBigInteger('video_views')->default(0);      // page_video_views
            $table->unsignedBigInteger('fans')->default(0);             // page_fans (total ese día)
            $table->timestamps();

            $table->unique(['meta_page_id', 'date']);
            $table->index('date');
        });

        // Audiencia (seguidores) por dimensión geográfica: país / ciudad
        Schema::create('meta_page_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->date('captured_date');
            $table->string('dimension', 20);   // 'country' | 'city'
            $table->string('key', 120);        // 'CO', 'Bogotá, Colombia', ...
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['meta_page_id', 'captured_date', 'dimension', 'key'], 'uq_page_audience');
            $table->index(['meta_page_id', 'dimension']);
        });

        // Reacciones por tipo a nivel de publicación (like, love, wow, haha, sorry, anger)
        Schema::create('meta_post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_post_id')->constrained('meta_posts')->cascadeOnDelete();
            $table->string('type', 20);        // like | love | wow | haha | sorry | anger
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();

            $table->unique(['meta_post_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_post_reactions');
        Schema::dropIfExists('meta_page_audiences');
        Schema::dropIfExists('meta_page_daily_metrics');
    }
};
