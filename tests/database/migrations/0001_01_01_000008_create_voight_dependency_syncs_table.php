<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_dependency_syncs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('environment_id')->constrained('voight_environments')->cascadeOnDelete();
            $table->string('lockfile_hash');
            $table->json('lockfile_paths')->nullable();
            $table->unsignedInteger('package_count')->default(0);
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_dependency_syncs');
    }
};
