<?php

namespace Tests\Feature\Concerns;

use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tablas mínimas (SQLite) de campañas, páginas, publicaciones, certificados y plan de medios para las pruebas. */
trait EsquemaPlanMedios
{
    protected function crearEsquemaPlanMedios(bool $conPlan = true): void
    {
        Schema::dropAllTables();
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->string('slug')->unique(); $t->timestamps(); });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('email')->unique(); $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->rememberToken(); $t->foreignId('role_id')->nullable(); $t->timestamps(); });
        Schema::create('social_accounts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable(); $t->string('usuario_app')->nullable(); $t->string('provider'); $t->string('provider_user_id'); $t->text('access_token'); $t->text('refresh_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->json('raw')->nullable(); $t->timestamps(); });
        Schema::create('meta_pages', function (Blueprint $t) { $t->id(); $t->string('page_id')->unique(); $t->string('name')->nullable(); $t->string('category')->nullable(); $t->string('instagram_business_account_id')->nullable(); $t->text('picture_url')->nullable(); $t->json('tasks')->nullable(); $t->boolean('visible_en_editor')->default(true); $t->string('medio_slug', 100)->nullable(); $t->text('app_usuarios')->nullable(); $t->timestamps(); });
        Schema::create('meta_page_user', function (Blueprint $t) { $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id')->nullable(); $t->string('usuario_app', 60)->nullable(); $t->foreignId('social_account_id')->nullable(); $t->text('page_access_token')->nullable(); $t->timestamp('expires_at')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('meta_page_favorites', function (Blueprint $t) { $t->id(); $t->foreignId('user_id'); $t->foreignId('meta_page_id'); $t->timestamps(); });
        Schema::create('meta_posts', function (Blueprint $t) {
            $t->id(); $t->foreignId('meta_page_id'); $t->foreignId('user_id'); $t->uuid('batch_uuid')->nullable(); $t->string('type'); $t->string('network')->nullable();
            $t->text('message')->nullable(); $t->string('link')->nullable(); $t->json('local_media')->nullable(); $t->json('fb_media_ids')->nullable();
            $t->string('fb_post_id')->nullable(); $t->string('fb_permalink_url')->nullable(); $t->string('status')->default('pending');
            $t->unsignedInteger('alcance')->nullable(); $t->unsignedInteger('visualizaciones')->nullable(); $t->unsignedInteger('interacciones')->nullable();
            $t->string('evidencia_path')->nullable(); $t->timestamp('last_insights_at')->nullable(); $t->text('error')->nullable();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_07_26_000001_create_campaigns_table.php'))->up();
        (require database_path('migrations/2025_10_03_100000_create_inteligencia_audiencia.php'))->up();
        (require database_path('migrations/2025_10_09_100000_consultor_ia_y_emociones.php'))->up();
        (require database_path('migrations/2026_10_10_000001_unificar_campanas.php'))->up();
        Role::query()->insert([['name' => 'Admin', 'slug' => 'admin'], ['name' => 'User', 'slug' => 'user']]);
        (require database_path('migrations/2026_10_20_000001_usuarios_roles_permisos.php'))->up();
        $migracion = require database_path('migrations/2026_10_26_000001_inscripcion_y_certificados_campanas.php');
        $migracion->up();
        $migracion->up(); // idempotente
        if ($conPlan) (require database_path('migrations/2026_10_27_000001_plan_de_medios.php'))->up();

    }
}
