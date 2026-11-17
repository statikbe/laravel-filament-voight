<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statikbe\FilamentVoight\Enums\PackageType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voight_alert_settings', function (Blueprint $table) {
            $table->json('package_types')->nullable();
        });

        DB::table('voight_alert_settings')
            ->whereNull('package_types')
            ->update(['package_types' => json_encode(array_column(PackageType::cases(), 'value'))]);
    }

    public function down(): void
    {
        Schema::table('voight_alert_settings', function (Blueprint $table) {
            $table->dropColumn('package_types');
        });
    }
};
