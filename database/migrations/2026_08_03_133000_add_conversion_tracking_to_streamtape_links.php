<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('error');
            $table->unsignedInteger('convert_attempts')->default(0)->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->dropColumn(['finished_at', 'convert_attempts']);
        });
    }
};
