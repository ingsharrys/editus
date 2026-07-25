<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de rendimiento basados en las consultas reales de la app:
 *
 * - meta_posts (meta_page_id, published_at): listados por página ordenados
 *   por fecha (vista de página, estadísticas, informes).
 * - meta_posts (status, published_at): recolección de métricas y reintentos
 *   sobre posts exitosos/fallidos recientes.
 * - meta_posts (fb_post_id): búsquedas por ID de Facebook (permalink, métricas).
 * - meta_page_user (meta_page_id, is_active, updated_at): resolución del
 *   page access token (la consulta más frecuente de todo el sistema).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            if (!Schema::hasIndex('meta_posts', 'idx_posts_page_published')) {
                $table->index(['meta_page_id', 'published_at'], 'idx_posts_page_published');
            }
            if (!Schema::hasIndex('meta_posts', 'idx_posts_status_published')) {
                $table->index(['status', 'published_at'], 'idx_posts_status_published');
            }
            if (!Schema::hasIndex('meta_posts', 'idx_posts_fb_post_id')) {
                $table->index('fb_post_id', 'idx_posts_fb_post_id');
            }
        });

        Schema::table('meta_page_user', function (Blueprint $table) {
            if (!Schema::hasIndex('meta_page_user', 'idx_pivot_page_active_updated')) {
                $table->index(['meta_page_id', 'is_active', 'updated_at'], 'idx_pivot_page_active_updated');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->dropIndex('idx_posts_page_published');
            $table->dropIndex('idx_posts_status_published');
            $table->dropIndex('idx_posts_fb_post_id');
        });

        Schema::table('meta_page_user', function (Blueprint $table) {
            $table->dropIndex('idx_pivot_page_active_updated');
        });
    }
};
