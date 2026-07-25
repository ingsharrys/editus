<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte multi-red (Facebook / Instagram):
 * - meta_posts.network: a qué red pertenece la publicación.
 * - meta_page_daily_metrics.network: serie diaria separada por red.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('meta_posts', 'network')) {
                $table->string('network', 20)->default('facebook')->after('type');
            }
        });

        Schema::table('meta_posts', function (Blueprint $table) {
            if (!Schema::hasIndex('meta_posts', 'idx_posts_network_published')) {
                $table->index(['network', 'published_at'], 'idx_posts_network_published');
            }
        });

        Schema::table('meta_page_daily_metrics', function (Blueprint $table) {
            if (!Schema::hasColumn('meta_page_daily_metrics', 'network')) {
                $table->string('network', 20)->default('facebook')->after('date');
            }
        });

        // Primero se crea el índice nuevo (también empieza por meta_page_id),
        // para que la foreign key pueda apoyarse en él y MySQL permita
        // eliminar el único viejo (error 1553 si se hace al revés).
        Schema::table('meta_page_daily_metrics', function (Blueprint $table) {
            if (!Schema::hasIndex('meta_page_daily_metrics', 'uq_page_date_network')) {
                $table->unique(['meta_page_id', 'date', 'network'], 'uq_page_date_network');
            }
        });

        Schema::table('meta_page_daily_metrics', function (Blueprint $table) {
            if (Schema::hasIndex('meta_page_daily_metrics', 'meta_page_daily_metrics_meta_page_id_date_unique')) {
                $table->dropUnique('meta_page_daily_metrics_meta_page_id_date_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meta_page_daily_metrics', function (Blueprint $table) {
            $table->dropUnique('uq_page_date_network');
            $table->unique(['meta_page_id', 'date']);
            $table->dropColumn('network');
        });

        Schema::table('meta_posts', function (Blueprint $table) {
            $table->dropIndex('idx_posts_network_published');
            $table->dropColumn('network');
        });
    }
};
