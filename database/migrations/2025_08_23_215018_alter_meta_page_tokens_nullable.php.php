<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
    {
        DB::statement('ALTER TABLE meta_page_user MODIFY page_access_token TEXT NULL');
        DB::statement('ALTER TABLE meta_page_user MODIFY expires_at DATETIME NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meta_page_user MODIFY page_access_token TEXT NOT NULL');
        DB::statement('ALTER TABLE meta_page_user MODIFY expires_at DATETIME NOT NULL');
    }
};
