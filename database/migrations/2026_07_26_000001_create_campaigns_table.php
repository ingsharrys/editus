<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campañas: toda publicación pertenece a una campaña.
 * - Se crean "Salud 2025" (asignada a todo lo existente) y
 *   "Esnoticia" (de sistema, reservada para los artículos automáticos).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_system')->default(false);  // reservada (Esnoticia)
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('meta_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('meta_posts', 'campaign_id')) {
                $table->foreignId('campaign_id')->nullable()->after('user_id')
                    ->constrained('campaigns')->nullOnDelete();
            }
        });

        Schema::table('meta_posts', function (Blueprint $table) {
            if (!Schema::hasIndex('meta_posts', 'idx_posts_campaign_published')) {
                $table->index(['campaign_id', 'published_at'], 'idx_posts_campaign_published');
            }
        });

        // Campañas iniciales + asignación del histórico
        $now = now();

        $saludId = DB::table('campaigns')->insertGetId([
            'name' => 'Salud 2025',
            'slug' => 'salud-2025',
            'description' => 'Campaña inicial: agrupa todas las publicaciones históricas.',
            'is_system' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaigns')->insert([
            'name' => 'Esnoticia',
            'slug' => 'esnoticia',
            'description' => 'Campaña de sistema: artículos replicados automáticamente desde esnoticia.',
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('meta_posts')->whereNull('campaign_id')->update(['campaign_id' => $saludId]);
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->dropIndex('idx_posts_campaign_published');
            $table->dropConstrainedForeignId('campaign_id');
        });
        Schema::dropIfExists('campaigns');
    }
};
