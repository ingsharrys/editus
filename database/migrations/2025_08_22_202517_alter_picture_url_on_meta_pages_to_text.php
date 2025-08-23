<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE meta_pages MODIFY picture_url TEXT NULL');
     
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE meta_pages MODIFY picture_url VARCHAR(255) NULL');
       
    }
};
