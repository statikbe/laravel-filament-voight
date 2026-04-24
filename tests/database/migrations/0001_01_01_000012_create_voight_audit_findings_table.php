<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_audit_findings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('audit_run_id')->constrained('voight_audit_runs')->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained('voight_packages')->cascadeOnDelete();
            $table->foreignUlid('vulnerability_id')->constrained('voight_vulnerabilities')->cascadeOnDelete();
            $table->string('installed_version');
            $table->string('fixed_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_audit_findings');
    }
};
