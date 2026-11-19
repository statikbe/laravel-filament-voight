<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_environments', 'hosting_id')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->foreignUlid('hosting_id')->nullable()->after('url')->constrained('voight_hostings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_environments', 'hosting_id')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hosting_id');
        });
    }
};
