<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persistent, stale-if-error cache of MovieBox API responses so the site
        // keeps serving content (from MySQL) even when the upstream API fails.
        Schema::create('catalog_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            $table->longText('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_snapshots');
    }
};
