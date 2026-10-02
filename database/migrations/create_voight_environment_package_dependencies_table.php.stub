<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_environment_package_dependencies', function (Blueprint $table) {
            $table->foreignUlid('parent_id')->constrained('voight_environment_packages')->cascadeOnDelete();
            $table->foreignUlid('child_id')->constrained('voight_environment_packages')->cascadeOnDelete();
            $table->string('constraint')->nullable();
            $table->string('kind');

            $table->primary(['parent_id', 'child_id']);
            $table->index('child_id', 'voight_env_pkg_deps_child_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_environment_package_dependencies');
    }
};
