<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void {
        Schema::create('meta_pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->unique();      // ID real de la página en Meta
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->string('instagram_business_account_id')->nullable();
            $table->string('picture_url')->nullable();
            $table->json('tasks')->nullable();        // tareas/permisos devueltos por Graph
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('meta_pages');
    }
};
