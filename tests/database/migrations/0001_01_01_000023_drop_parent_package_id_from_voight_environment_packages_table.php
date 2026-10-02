<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('voight_environment_packages', 'parent_package_id')) {
            return;
        }

        Schema::table('voight_environment_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_package_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('voight_environment_packages', 'parent_package_id')) {
            return;
        }

        Schema::table('voight_environment_packages', function (Blueprint $table) {
            $table->foreignUlid('parent_package_id')->nullable()->constrained('voight_packages')->nullOnDelete();
        });
    }
};
