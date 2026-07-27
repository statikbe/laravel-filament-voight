<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voight_alert_notification_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('alert_setting_id')->constrained('voight_alert_settings')->cascadeOnDelete();
            $table->foreignUlid('vulnerability_id')->constrained('voight_vulnerabilities')->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained('voight_packages')->cascadeOnDelete();
            $table->timestamp('notified_at');
            $table->timestamps();

            $table->unique(
                ['alert_setting_id', 'vulnerability_id', 'package_id'],
                'voight_alert_notification_logs_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voight_alert_notification_logs');
    }
};
