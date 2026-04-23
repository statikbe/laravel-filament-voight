<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_environment_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('environment_id')->constrained('voight_environments')->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained('voight_packages')->cascadeOnDelete();
            $table->string('version');
            $table->boolean('is_direct')->default(true);
            $table->boolean('is_dev')->default(false);
            $table->foreignUlid('parent_package_id')->nullable()->constrained('voight_packages')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_environment_packages');
    }
};
