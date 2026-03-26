<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_audit_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('environment_id')->constrained('voight_environments')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_audit_runs');
    }
};
