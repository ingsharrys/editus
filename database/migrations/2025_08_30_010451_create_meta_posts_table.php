<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void {
        Schema::create('meta_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['text','photo','video']);
            $table->text('message')->nullable();
            $table->string('link')->nullable();

            $table->json('local_media')->nullable();   // rutas guardadas en storage (si aplica)
            $table->json('fb_media_ids')->nullable();  // ids devueltos por FB al subir fotos

            $table->string('fb_post_id')->nullable();
            $table->string('fb_permalink_url')->nullable();

            $table->enum('status', ['pending','success','fail'])->default('pending');
            $table->text('error')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('meta_posts');
    }
};
