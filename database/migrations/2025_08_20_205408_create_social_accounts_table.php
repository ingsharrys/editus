<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // 'facebook'
            $table->string('provider_user_id'); // ID del usuario en Meta
            $table->text('access_token');
            $table->text('refresh_token')->nullable(); // FB usualmente no da refresh_token
            $table->timestamp('expires_at')->nullable();
            $table->json('raw')->nullable(); // guardar payload crudo opcional
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
