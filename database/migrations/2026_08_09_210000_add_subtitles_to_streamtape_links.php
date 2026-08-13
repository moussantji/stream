<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->json('subtitle_urls')->nullable()->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->dropColumn('subtitle_urls');
        });
    }
};
