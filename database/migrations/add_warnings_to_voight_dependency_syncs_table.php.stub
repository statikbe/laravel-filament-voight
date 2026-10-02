<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_dependency_syncs', 'warnings')) {
            return;
        }

        Schema::table('voight_dependency_syncs', function (Blueprint $table) {
            $table->json('warnings')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_dependency_syncs', 'warnings')) {
            return;
        }

        Schema::table('voight_dependency_syncs', function (Blueprint $table) {
            $table->dropColumn('warnings');
        });
    }
};
