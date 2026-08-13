<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->string('folder', 150)->nullable()->after('resolution');
        });

        Schema::create('streamtape_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('parent', 64)->default('');
            $table->string('folder_id', 64)->unique();
            $table->timestamps();

            $table->unique(['name', 'parent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streamtape_folders');
        Schema::table('streamtape_links', function (Blueprint $table) {
            $table->dropColumn('folder');
        });
    }
};
