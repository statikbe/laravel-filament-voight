<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voight_alert_settings', function (Blueprint $table) {
            $table->timestamp('last_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('voight_alert_settings', function (Blueprint $table) {
            $table->dropColumn('last_sent_at');
        });
    }
};
