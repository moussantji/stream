<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A growing local library: every distinct movie/series the API ever
        // returns is upserted here so all received data is persisted in MySQL.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('subject_id')->unique();
            $table->unsignedTinyInteger('subject_type')->default(0)->index();
            $table->string('title')->nullable();
            $table->text('cover')->nullable();
            $table->longText('description')->nullable();
            $table->unsignedSmallInteger('year')->nullable()->index();
            $table->decimal('imdb_rating', 3, 1)->nullable();
            $table->string('country')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('genres')->nullable();
            $table->text('detail_path')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamps();

            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
