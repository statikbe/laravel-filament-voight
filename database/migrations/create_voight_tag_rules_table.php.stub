<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_tag_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tag_id')->constrained('voight_tags')->cascadeOnDelete();
            $table->string('taggable_type');
            $table->string('source_page');
            $table->string('tab')->nullable();
            $table->json('table_state');
            $table->json('description');
            $table->text('query_sql');
            $table->json('query_bindings');
            $table->string('status')->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_match_count')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_tag_rules');
    }
};
