<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_osv_scans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scanner_scan_id', 26)->nullable()->unique();
            $table->string('type');
            $table->string('status');
            $table->foreignUlid('environment_id')->nullable()->constrained('voight_environments')->cascadeOnDelete();
            $table->foreignUlid('nightly_sweep_id')->nullable()->constrained('voight_nightly_sweeps')->cascadeOnDelete();
            $table->string('trigger');
            $table->json('meta');
            $table->unsignedTinyInteger('resubmits')->default(0);
            $table->string('input_path')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['nightly_sweep_id', 'status'], 'voight_osv_scans_sweep_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_osv_scans');
    }
};
