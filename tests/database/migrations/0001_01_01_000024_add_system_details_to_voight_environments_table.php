<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('voight_environments', 'system_details')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->json('system_details')->nullable()->after('scanned_at');
            $table->timestamp('system_details_received_at')->nullable()->after('system_details');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('voight_environments', 'system_details')) {
            return;
        }

        Schema::table('voight_environments', function (Blueprint $table) {
            $table->dropColumn(['system_details', 'system_details_received_at']);
        });
    }
};
