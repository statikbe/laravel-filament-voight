<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_taggables', function (Blueprint $table) {
            $table->foreignUlid('tag_id')->constrained('voight_tags')->cascadeOnDelete();
            // A string id: projects use integer keys, the other taggable models ULIDs.
            $table->string('taggable_type');
            $table->string('taggable_id', 36);

            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_taggables');
    }
};
