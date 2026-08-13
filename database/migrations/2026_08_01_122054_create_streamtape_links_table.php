<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streamtape_links', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id', 64)->index();
            $table->unsignedTinyInteger('subject_type')->default(1);
            $table->string('title', 300);
            $table->unsignedSmallInteger('season')->default(0);
            $table->unsignedInteger('episode')->default(0);
            $table->unsignedSmallInteger('resolution')->default(0);
            $table->string('source_url', 1000)->nullable();
            $table->string('file_id', 64)->nullable();
            $table->string('streamtape_url', 300)->nullable();
            $table->string('status', 32)->default('new');
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('bytes_loaded')->nullable();
            $table->unsignedBigInteger('bytes_total')->nullable();
            $table->timestamps();

            $table->index(['subject_id', 'season', 'episode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streamtape_links');
    }
};
