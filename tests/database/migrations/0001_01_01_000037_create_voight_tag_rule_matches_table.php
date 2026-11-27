<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_tag_rule_matches', function (Blueprint $table) {
            $table->foreignUlid('tag_rule_id')->constrained('voight_tag_rules')->cascadeOnDelete();
            $table->string('taggable_type');
            $table->string('taggable_id', 36);

            $table->unique(['tag_rule_id', 'taggable_type', 'taggable_id'], 'voight_tag_rule_matches_unique');
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_tag_rule_matches');
    }
};
