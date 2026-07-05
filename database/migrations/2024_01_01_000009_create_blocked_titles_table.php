<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_titles', function (Blueprint $table) {
            $table->id();
            // A title (matched case-insensitively as a substring) OR an exact
            // subjectId. Hidden from discovery but still reachable via search.
            $table->string('term')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_titles');
    }
};
