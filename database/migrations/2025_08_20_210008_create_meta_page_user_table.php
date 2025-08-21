<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void {
        Schema::create('meta_page_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            $table->text('page_access_token');       // token de la página
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['meta_page_id','user_id']);  // 1 token por user-page
        });
    }
    public function down(): void {
        Schema::dropIfExists('meta_page_user');
    }
};
