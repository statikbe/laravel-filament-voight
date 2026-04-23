<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_vulnerable_package_ranges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vulnerability_id')->constrained('voight_vulnerabilities')->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained('voight_packages')->cascadeOnDelete();
            $table->string('affected_range');
            $table->string('fixed_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_vulnerable_package_ranges');
    }
};
