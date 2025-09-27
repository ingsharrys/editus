<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_posts', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_posts', 'last_insights_at')) {
                $t->timestamp('last_insights_at')->nullable()->after('published_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $t) {
            if (Schema::hasColumn('meta_posts', 'last_insights_at')) {
                $t->dropColumn('last_insights_at');
            }
        });
    }
};
