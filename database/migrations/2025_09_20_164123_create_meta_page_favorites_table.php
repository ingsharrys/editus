<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meta_page_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'meta_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_page_favorites');
    }
};
